#!/bin/sh
# Full rehearsal: rebuild the fixture, reset the simulated shared host, run the
# installer, then assert the result. Everything happens in a scratch directory
# so a run never touches anything that matters.
#
# Usage: sh tests/run.sh
set -e

PROJ=$(cd "$(dirname "$0")/.." && pwd)
SCRATCH=${SCRATCH:-/tmp/claude-1007/-home-freelancer/2989d2e8-1605-4f34-8cde-6e7506863218/scratchpad}
HOST=$SCRATCH/host
DOCROOT=$HOST/public_html
SOCK=$SCRATCH/mysql/run/mysql.sock
DBHOST=${DBHOST:-127.0.0.1:33306}
DBNAME=${DBNAME:-gnametest_db}
DBUSER=${DBUSER:-gnametest_u}
DBPASS=${DBPASS:-'Str0ng#Pass!'}

echo "== lint =="
for f in "$PROJ"/installer/*.php; do php -l "$f" >/dev/null || exit 1; done
echo "  all installer files parse"

echo
echo "== unit =="
php "$PROJ/tests/unit.php" | tail -1

echo
echo "== rebuild fixture =="
php "$PROJ/tests/make_fixture.php" "$PROJ/fixture" | sed 's/^/  /'

echo
echo "== reset the simulated host =="
mysql --socket="$SOCK" -u root \
  -e "DROP DATABASE IF EXISTS $DBNAME; CREATE DATABASE $DBNAME DEFAULT CHARACTER SET utf8mb4;"
rm -rf "$DOCROOT"
mkdir -p "$DOCROOT"
cp "$PROJ"/installer/install.php "$PROJ"/installer/lib_*.php "$DOCROOT/"
cp "$PROJ"/fixture/mysite.zip "$PROJ"/fixture/mysite_dev.sql "$DOCROOT/"
echo "SENTINEL-40755308-stable" > "$DOCROOT/_sentinel.txt"
# The real host (GNAME) leaves exactly this in the web root on a new account,
# and index.html is served before index.php. Reproduce it.
EXPECT_PLACEHOLDER=${EXPECT_PLACEHOLDER:-1}
if [ "$EXPECT_PLACEHOLDER" = "1" ]; then
  printf '<!doctype html><title>New account</title><h1>ACCOUNT CREATED</h1>\n' \
    > "$DOCROOT/index.html"
  echo "  database dropped and recreated, docroot staged WITH a host placeholder index.html"
else
  echo "  database dropped and recreated, docroot staged"
fi

echo
echo "== confirm the web server serves THIS docroot =="
PORT=$(cat "$HOST/port.txt")
GOT=$(curl -s --max-time 5 "http://127.0.0.1:$PORT/_sentinel.txt" || true)
if [ "$GOT" != "SENTINEL-40755308-stable" ]; then
  echo "  REFUSING TO TEST: port $PORT is not serving my docroot (got: '$GOT')"
  exit 1
fi
echo "  port $PORT is serving my docroot"

echo
echo "== run the installer =="
cd "$DOCROOT"
php install.php --cli \
  --root="$DOCROOT" \
  --dbhost="$DBHOST" --dbname="$DBNAME" --dbuser="$DBUSER" --dbpass="$DBPASS" \
  --baseurl="http://127.0.0.1:$PORT" > "$HOST/report.json" 2> "$HOST/report.err" || true
if [ -s "$HOST/report.err" ]; then
  echo "  stderr from the installer:"; sed 's/^/    /' "$HOST/report.err"
fi
python3 "$PROJ/tests/show_report.py" "$HOST/report.json" | sed 's/^/  /'

echo
echo "== assert =="
DOCROOT="$DOCROOT" BASEURL="http://127.0.0.1:$PORT" REPORT="$HOST/report.json" \
  DBHOST="$DBHOST" DBNAME="$DBNAME" DBUSER="$DBUSER" DBPASS="$DBPASS" \
  EXPECT_PLACEHOLDER="$EXPECT_PLACEHOLDER" \
  php "$PROJ/tests/e2e.php"
