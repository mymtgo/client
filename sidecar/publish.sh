#!/usr/bin/env bash
# Cross-publishes the Windows sidecar from macOS or Linux and copies it into the app's resources.
# Usage: sidecar/publish.sh <version>   (version = NATIVEPHP_APP_VERSION, e.g. 0.30.0)
set -euo pipefail
cd "$(dirname "$0")"
VERSION="${1:?version required, e.g. 0.30.0}"
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  echo "error: version must look like 0.30.0, got '$VERSION'" >&2
  exit 2
fi
OUT="../resources/sidecar"
PKG_ID="mtgosdk"
PKG_VERSION="1.7.0.20260903"

if [ -z "${DOTNET_ROOT:-}" ]; then
  if [ -d "/opt/homebrew/opt/dotnet/libexec" ]; then
    DOTNET_ROOT="/opt/homebrew/opt/dotnet/libexec"
  elif command -v dotnet >/dev/null 2>&1; then
    DOTNET_BIN="$(command -v dotnet)"
    # Resolve symlinks (Homebrew's dotnet is one) so DOTNET_ROOT points at the real install.
    while [ -L "$DOTNET_BIN" ]; do
      LINK_TARGET="$(readlink "$DOTNET_BIN")"
      case "$LINK_TARGET" in
        /*) DOTNET_BIN="$LINK_TARGET" ;;
        *) DOTNET_BIN="$(dirname "$DOTNET_BIN")/$LINK_TARGET" ;;
      esac
    done
    DOTNET_ROOT="$(cd "$(dirname "$DOTNET_BIN")" && pwd)"
  else
    echo "error: dotnet not found on PATH and DOTNET_ROOT is unset; install the .NET 10 SDK or set DOTNET_ROOT" >&2
    exit 1
  fi
fi
export DOTNET_ROOT
export PATH="/opt/homebrew/bin:$PATH"

dotnet publish MyMtgo.Sidecar -c Release -r win-x64 --self-contained true \
  -p:PublishSingleFile=true -p:PublishTrimmed=false -p:Version="$VERSION" \
  -o artifacts/publish

PKG="$HOME/.nuget/packages/$PKG_ID/$PKG_VERSION"
NUPKG="$PKG/$PKG_ID.$PKG_VERSION.nupkg"

# Everything is assembled in a staging folder and only moved into $OUT once all three files
# exist. Copying straight into $OUT would leave a bundle holding an exe with no attribution
# whenever a package revision drops its NOTICE, because set -e aborts after the exe is in place.
STAGE="artifacts/stage"
rm -rf "$STAGE"
mkdir -p "$STAGE"
trap 'rm -rf "$STAGE"' EXIT

cp "artifacts/publish/mymtgo-helper.exe" "$STAGE/mymtgo-helper.exe"

if [ ! -f "$PKG/NOTICE" ]; then
  echo "error: no NOTICE in $PKG; refusing to ship the exe without attribution" >&2
  exit 1
fi
cp "$PKG/NOTICE" "$STAGE/MTGOSDK-NOTICE.txt"

if [ -f "$PKG/LICENSE" ]; then
  cp "$PKG/LICENSE" "$STAGE/MTGOSDK-LICENSE.txt"
elif LICENSE_ENTRY="$(unzip -Z1 "$NUPKG" 2>/dev/null | grep -i '^LICENSE$' | head -n 1)" && [ -n "$LICENSE_ENTRY" ]; then
  # Extract by the entry's real name so a differently cased LICENSE does not abort the publish.
  unzip -p "$NUPKG" "$LICENSE_ENTRY" > "$STAGE/MTGOSDK-LICENSE.txt"
else
  # MTGOSDK 1.7.0.20260903 ships no LICENSE file in the package; fall back to the
  # license expression recorded in its nuspec so the notice folder still has something.
  LICENSE_EXPR="$(grep -o '<license type="expression">[^<]*</license>' "$PKG/MTGOSDK.nuspec" | sed -E 's/<[^>]+>//g')"
  LICENSE_URL="$(grep -o '<licenseUrl>[^<]*</licenseUrl>' "$PKG/MTGOSDK.nuspec" | sed -E 's/<[^>]+>//g')"
  {
    echo "MTGOSDK $PKG_VERSION does not bundle a LICENSE file."
    echo "Its NuGet package declares license: ${LICENSE_EXPR:-unknown}"
    echo "See: ${LICENSE_URL:-https://www.nuget.org/packages/MTGOSDK}"
  } > "$STAGE/MTGOSDK-LICENSE.txt"
fi

mkdir -p "$OUT"
mv -f "$STAGE/mymtgo-helper.exe" "$OUT/mymtgo-helper.exe"
mv -f "$STAGE/MTGOSDK-NOTICE.txt" "$OUT/MTGOSDK-NOTICE.txt"
mv -f "$STAGE/MTGOSDK-LICENSE.txt" "$OUT/MTGOSDK-LICENSE.txt"
rm -rf "$STAGE"

ls -la "$OUT"
