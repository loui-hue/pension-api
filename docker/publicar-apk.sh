#!/bin/sh
# Publica la APK en la página de descarga del servidor local (http://192.168.137.1:8000/app/).
# Uso, desde la carpeta pension-api:   sh docker/publicar-apk.sh
# Antes: compilar la app para el hotspot, en la carpeta pension_app:
#   flutter build apk --release --dart-define=API_URL=http://192.168.137.1:8000/api
set -e
cd "$(dirname "$0")/descargas"
APK="../../../pension_app/build/app/outputs/flutter-apk/app-release.apk"
[ -f "$APK" ] || { echo "No existe $APK: compila la app primero."; exit 1; }

cp "$APK" pension-dona-roberta.apk
TAMANO=$(awk "BEGIN { printf \"%.0f\", $(wc -c < pension-dona-roberta.apk) / 1048576 }")
FECHA=$(date +%d/%m/%Y)
sed -e "s/APK_TAMANO/$TAMANO/" -e "s#APK_FECHA#$FECHA#" plantilla.html > index.html
echo "Publicada: $TAMANO MB, versión del $FECHA → http://192.168.137.1:8000/app/"
