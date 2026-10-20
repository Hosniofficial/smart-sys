<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use mysqli;
use RuntimeException;

final class MySqlSchemaDumpImporter
{
    public static function import(mysqli $server, string $schemaPath): void
    {
        $contents = file_get_contents($schemaPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the schema snapshot for isolated MySQL tests.');
        }

        $delimiter = ';';
        $buffer = '';
        foreach (preg_split('/\R/', $contents) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                $delimiter = $match[1];
                continue;
            }
            $buffer .= $line . "\n";
            while (($statement = self::takeStatement($buffer, $delimiter)) !== null) {
                $trimmed = trim($statement);
                if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                    $server->query($trimmed);
                    if ($server->field_count > 0) {
                        $server->store_result()?->free();
                    }
                }
            }
        }
    }

    private static function takeStatement(string &$buffer, string $delimiter): ?string
    {
        $length = strlen($buffer);
        $quote = null;
        $index = 0;

        while ($index < $length) {
            $char = $buffer[$index];
            if ($quote !== null) {
                if ($char === '\\') {
                    $index += 2;
                    continue;
                }
                if ($char === $quote) {
                    if ($index + 1 < $length && $buffer[$index + 1] === $quote) {
                        $index += 2;
                        continue;
                    }
                    $quote = null;
                }
                $index++;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $index++;
                continue;
            }
            if (substr($buffer, $index, 2) === '/*') {
                $commentEnd = strpos($buffer, '*/', $index + 2);
                if ($commentEnd === false) {
                    return null;
                }
                $index = $commentEnd + 2;
                continue;
            }
            if (substr($buffer, $index, 2) === '--' || $char === '#') {
                $lineEnd = strpos($buffer, "\n", $index);
                if ($lineEnd === false) {
                    return null;
                }
                $index = $lineEnd + 1;
                continue;
            }
            if (substr($buffer, $index, strlen($delimiter)) === $delimiter) {
                $statement = substr($buffer, 0, $index);
                $buffer = substr($buffer, $index + strlen($delimiter));
                return $statement;
            }
            $index++;
        }

        return null;
    }
}
