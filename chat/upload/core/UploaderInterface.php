<?php
interface UploaderInterface {
    /** Devuelve respuesta para iniciar (firmar URL / crear uploadId / etc.) */
    public function init(array $req): array;

    /** Recibe una parte (solo chunked). */
    public function part(array $req): array;

    /** Finaliza y guarda en FileS3. */
    public function complete(array $req): array;
}