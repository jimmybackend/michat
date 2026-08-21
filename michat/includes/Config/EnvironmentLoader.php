<?php
declare(strict_types=1);

final class EnvironmentLoader
{
    public function loadIfPresent(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('No se pudo leer la configuración de entorno.');
        }

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException('Configuración de entorno inválida en la línea '.($number + 1).'.');
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new RuntimeException('Nombre de variable de entorno inválido en la línea '.($number + 1).'.');
            }
            if (getenv($key) !== false) {
                continue;
            }

            $value = $this->parseValue(substr($line, $separator + 1), $number + 1);
            if (!putenv($key.'='.$value)) {
                throw new RuntimeException('No se pudo aplicar la configuración de entorno.');
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function parseValue(string $raw, int $line): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if ($quote === "'" || $quote === '"') {
            if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
                throw new RuntimeException('Valor sin cerrar en la línea '.$line.'.');
            }
            $value = substr($value, 1, -1);
            return $quote === '"' ? stripcslashes($value) : $value;
        }

        return (string)preg_replace('/\s+#.*$/', '', $value);
    }
}
