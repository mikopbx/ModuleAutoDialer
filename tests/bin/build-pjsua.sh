#!/bin/bash
#
# Сборка статического бинарника pjsua для Linux (x86_64 и aarch64).
# Запускается на dev-машине с Docker Desktop (Mac/Linux).
#
# Результат:
#   tests/bin/pjsua-linux-x86_64
#   tests/bin/pjsua-linux-aarch64
#
# Использование:
#   cd tests/bin && ./build-pjsua.sh
#   cd tests/bin && ./build-pjsua.sh x86_64      # только x86_64
#   cd tests/bin && ./build-pjsua.sh aarch64      # только aarch64

set -euo pipefail

PJSIP_VERSION="2.14.1"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Dockerfile для сборки
create_dockerfile() {
    cat <<'DOCKERFILE'
FROM alpine:3.19 AS builder

ARG PJSIP_VERSION=2.14.1

RUN apk add --no-cache \
    build-base \
    linux-headers \
    openssl-dev \
    openssl-libs-static \
    alsa-lib-dev \
    wget

WORKDIR /build

RUN wget -q "https://github.com/pjsip/pjproject/archive/refs/tags/${PJSIP_VERSION}.tar.gz" \
    -O pjproject.tar.gz && \
    tar xzf pjproject.tar.gz && \
    mv "pjproject-${PJSIP_VERSION}" pjproject

WORKDIR /build/pjproject

# Отключаем видео, GUI, звук — нужен только null-audio для тестирования
RUN cat > pjlib/include/pj/config_site.h <<'EOF'
#define PJ_HAS_SSL 0
#define PJMEDIA_HAS_VIDEO 0
#define PJMEDIA_AUDIO_DEV_HAS_ALSA 0
#define PJMEDIA_AUDIO_DEV_HAS_PORTAUDIO 0
EOF

RUN ./configure \
    --disable-video \
    --disable-v4l2 \
    --disable-sound \
    --disable-openh264 \
    --disable-libyuv \
    --disable-libwebrtc \
    --disable-ssl \
    --enable-epoll \
    CFLAGS="-O2 -static" \
    LDFLAGS="-static" && \
    make dep && \
    make -j$(nproc)

# Собираем pjsua
RUN cd pjsip-apps/bin && \
    ls -la pjsua-* && \
    cp pjsua-* /build/pjsua

FROM scratch
COPY --from=builder /build/pjsua /pjsua
DOCKERFILE
}

build_arch() {
    local arch="$1"
    local platform

    case "$arch" in
        x86_64)  platform="linux/amd64" ;;
        aarch64) platform="linux/arm64" ;;
        *)       echo "Unknown arch: $arch"; exit 1 ;;
    esac

    local output="$SCRIPT_DIR/pjsua-linux-${arch}"
    local dockerfile="$SCRIPT_DIR/.Dockerfile.pjsua"

    echo "=== Building pjsua for ${arch} (${platform}) ==="

    create_dockerfile > "$dockerfile"

    # Используем buildx для кросс-компиляции
    local container_name="pjsua-build-${arch}-$$"

    docker buildx build \
        --platform "$platform" \
        --build-arg "PJSIP_VERSION=${PJSIP_VERSION}" \
        -f "$dockerfile" \
        -t "pjsua-builder:${arch}" \
        --load \
        "$SCRIPT_DIR"

    # Извлекаем бинарник из образа (scratch-образ требует явную команду)
    docker create --name "$container_name" --platform "$platform" "pjsua-builder:${arch}" /bin/true 2>/dev/null
    docker cp "$container_name:/pjsua" "$output"
    docker rm "$container_name" >/dev/null 2>&1

    chmod +x "$output"
    rm -f "$dockerfile"

    echo "=== Built: ${output} ==="
    file "$output"
    echo ""
}

# Определяем какие архитектуры собирать
if [ "${1:-}" = "x86_64" ]; then
    build_arch x86_64
elif [ "${1:-}" = "aarch64" ]; then
    build_arch aarch64
else
    build_arch x86_64
    build_arch aarch64
fi

echo "Done."
