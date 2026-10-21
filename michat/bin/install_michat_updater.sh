#!/usr/bin/env bash
set -euo pipefail
[[ "$EUID" -eq 0 ]] || { echo "Ejecuta con sudo" >&2; exit 1; }
PHP_USER="${1:-}"
[[ -n "$PHP_USER" ]] || { echo "Uso: sudo bash michat/bin/install_michat_updater.sh USUARIO_REAL_PHP_FPM" >&2; exit 2; }
id "$PHP_USER" >/dev/null
ROOT="/var/www/michat"
[[ -d "$ROOT/.git" ]] || { echo "No existe checkout Git en $ROOT" >&2; exit 2; }
REPO_USER="$(stat -c %U "$ROOT/.git")"
id "$REPO_USER" >/dev/null
SOURCE="$ROOT/michat/bin/michat-updater.php"
TARGET="/usr/local/sbin/michat-updater"
install -d -o root -g root -m 0755 /etc/michat
install -o root -g root -m 0755 "$SOURCE" "$TARGET"
python3 - "$ROOT" "$REPO_USER" "$PHP_USER" <<'PY'
import json,os,sys,tempfile
root,user,php=sys.argv[1:]
fd,tmp=tempfile.mkstemp(dir='/etc/michat',prefix='.updater-')
with os.fdopen(fd,'w') as f: json.dump({'repo_root':root,'repo_user':user,'php_user':php},f)
os.chmod(tmp,0o600)
os.replace(tmp,'/etc/michat/updater.json')
PY
chown root:root /etc/michat/updater.json
printf '%s ALL=(root) NOPASSWD: %s probe, %s check, %s apply, %s apply-stash\n' "$PHP_USER" "$TARGET" "$TARGET" "$TARGET" > /etc/sudoers.d/michat-updater
chmod 0440 /etc/sudoers.d/michat-updater
visudo -cf /etc/sudoers.d/michat-updater
runuser -u "$PHP_USER" -- sudo -n "$TARGET" probe
echo "MiChat updater instalado; sin actualización ejecutada."
