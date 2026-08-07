#!/usr/bin/env bash
#
# check-line-endings.sh <sha-base>
#
# Falla si un archivo cambia de estilo de fin de línea respecto a la base.
#
# POR QUÉ EXISTE
# Un script de sustitución que lee y escribe en modo texto reescribe el archivo
# entero con LF. El contenido queda bien, pero git ve todas las líneas
# modificadas: un cambio real de 15 líneas se presenta como un diff de 600 y
# revisarlo deja de ser viable. Ya ocurrió con ocho archivos de este repo.
#
# La comprobación es sobre el CAMBIO, no sobre el estilo: da igual que un
# archivo sea CRLF o LF, lo que no puede es cambiar de uno a otro sin que sea
# deliberado.

set -euo pipefail

BASE="${1:-}"

if [ -z "$BASE" ] || ! git cat-file -e "${BASE}^{commit}" 2>/dev/null; then
    echo "Sin commit base utilizable ('${BASE}'): se omite la comprobación."
    exit 0
fi

# Proporción de líneas CRLF de un blob: "0", "1" o un valor intermedio si es
# mixto. Se compara la proporción, no el número absoluto, porque añadir o quitar
# líneas cambia el conteo sin cambiar el estilo.
estilo() {
    local contenido="$1"
    local total crlf
    total=$(printf '%s' "$contenido" | wc -l | tr -d ' ')
    if [ "$total" -eq 0 ]; then
        echo "vacio"
        return
    fi
    crlf=$(printf '%s' "$contenido" | grep -c $'\r$' || true)
    if [ "$crlf" -eq 0 ]; then
        echo "LF"
    elif [ "$crlf" -eq "$total" ]; then
        echo "CRLF"
    else
        echo "MIXTO"
    fi
}

fallos=0

# Solo archivos modificados (M). Los nuevos (A) no tienen con qué compararse y
# los borrados (D) no importan.
while IFS= read -r archivo; do
    [ -n "$archivo" ] || continue
    [ -f "$archivo" ] || continue

    antes=$(git show "${BASE}:${archivo}" 2>/dev/null) || continue
    ahora=$(cat "$archivo")

    estilo_antes=$(estilo "$antes")
    estilo_ahora=$(estilo "$ahora")

    if [ "$estilo_antes" = "vacio" ] || [ "$estilo_ahora" = "vacio" ]; then
        continue
    fi

    if [ "$estilo_antes" != "$estilo_ahora" ]; then
        echo "  ✗ ${archivo}: ${estilo_antes} -> ${estilo_ahora}" >&2
        fallos=$((fallos + 1))
    fi
done < <(git diff --name-only --diff-filter=M "$BASE" HEAD)

if [ "$fallos" -ne 0 ]; then
    cat >&2 <<'MSG'

ABORTADO: hay archivos que cambiaron de estilo de fin de línea.

Casi siempre es un script de sustitución que leyó y escribió en modo texto.
El contenido puede estar bien, pero el diff se vuelve irrevisable.

Para arreglarlo, reconvierte los archivos afectados a su estilo original:

    python3 - <<'PY'
    for p in ['ruta/al/archivo.php']:
        raw = open(p, 'rb').read()
        assert b'\r\n' not in raw
        open(p, 'wb').write(raw.replace(b'\n', b'\r\n'))
    PY

Y para la próxima: abre los archivos en binario ('rb'/'wb') y preserva el
estilo que traían.
MSG
    exit 1
fi

echo "OK — ningún archivo cambió de estilo de fin de línea."
