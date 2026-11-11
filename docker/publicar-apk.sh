#!/bin/sh
# Publica la APK en la página de descarga:
#  - en el servidor local de la pensión: http://192.168.137.1:8000/app/
#  - en el portal en la nube (VPS):      https://pension.169-58-97-170.sslip.io/app/
#    (así el jurado o los comensales la descargan sin que la PC de la pensión esté encendida)
#
# Uso, desde la carpeta pension-api:   sh docker/publicar-apk.sh
# Antes: compilar la app final, en la carpeta pension_app:
#   flutter build apk --release --dart-define=API_URL=http://192.168.137.1:8000/api \
#     --dart-define=PORTAL_URL=https://pension.169-58-97-170.sslip.io/api
set -e
cd "$(dirname "$0")/descargas"
APK="../../../pension_app/build/app/outputs/flutter-apk/app-release.apk"
[ -f "$APK" ] || { echo "No existe $APK: compila la app primero."; exit 1; }

cp "$APK" pension-dona-roberta.apk
TAMANO=$(awk "BEGIN { printf \"%.0f\", $(wc -c < pension-dona-roberta.apk) / 1048576 }")
FECHA=$(date -r "$APK" +%d/%m/%Y)
VERSION=$(sed -n 's/^version: *\([0-9.]*\).*/\1/p' ../../../pension_app/pubspec.yaml)
sed -e "s/APK_TAMANO/$TAMANO/" -e "s/APK_VERSION/$VERSION/" -e "s#APK_FECHA#$FECHA#" plantilla.html > index.html
echo "Publicada en local: v$VERSION, $TAMANO MB, compilada el $FECHA → http://192.168.137.1:8000/app/"

# Copia al portal en la nube (solo la carpeta propia /srv/pension del VPS compartido).
VPS="root@169.58.97.170"
LLAVE="$HOME/.ssh/pension_vps"
if [ -f "$LLAVE" ]; then
  tar -czf - index.html pension-dona-roberta.apk qr-apk.png \
    | ssh -i "$LLAVE" -o BatchMode=yes -o ConnectTimeout=15 "$VPS" \
        'mkdir -p /srv/pension/docker/descargas && tar -xzf - -C /srv/pension/docker/descargas' \
    && echo "Publicada en la nube → https://pension.169-58-97-170.sslip.io/app/" \
    || echo "AVISO: no se pudo copiar al VPS (¿sin Internet?). La versión local sí quedó publicada."
else
  echo "AVISO: no está la llave $LLAVE; solo se publicó en local."
fi
