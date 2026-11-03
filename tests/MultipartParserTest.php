<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartParser;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The part headers as the SAPI reads them: names are case- and
 * whitespace-insensitive, a value keeps everything after the first colon, and
 * a part that names no field is dropped without ending the read.
 */
#[Test]
#[Covers(MultipartParser::class)]
final class MultipartParserTest
{
    private const string TYPE = 'multipart/form-data; boundary=b';

    /** @param list<string> $parts raw part segments, headers and body */
    private function body(array $parts): string
    {
        return '--b' . implode('', array_map(static fn(string $part): string => "\r\n" . $part . "\r\n--b", $parts)) . "--\r\n";
    }

    public function headerNamesAreReadTrimmedAndAValueKeepsItsColons(): void
    {
        $body = $this->body([
            "X-Unrelated\r\n  Content-Disposition : form-data; name=\"file\"; filename=\"c:report.txt\"\r\nContent-Type: text/plain; note=a:b\r\n\r\nhello",
        ]);

        Assert::same((new MultipartParser())->parse($body, self::TYPE), [
            ['name' => 'file', 'filename' => 'c:report.txt', 'contentType' => 'text/plain; note=a:b', 'value' => 'hello'],
        ]);
    }

    public function aPartWithoutADispositionIsDroppedAndTheReadGoesOn(): void
    {
        $body = $this->body([
            "Content-Type: text/plain\r\n\r\norphan",
            "Content-Disposition: form-data; name=kept\r\n\r\nvalue",
        ]);

        Assert::same((new MultipartParser())->parse($body, self::TYPE), [
            ['name' => 'kept', 'filename' => null, 'contentType' => null, 'value' => 'value'],
        ]);
    }

    public function aBodyWithoutTheDelimiterHasNoParts(): void
    {
        Assert::same((new MultipartParser())->parse("\r\nContent-Disposition: form-data; name=a\r\n\r\nx\r\n", self::TYPE), []);
    }
}
