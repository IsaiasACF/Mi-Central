FROM php:8.5-cli

ARG WHISPER_CPP_VERSION=v1.8.6

ENV TZ=America/Santiago

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates cmake cron curl ffmpeg tzdata \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://github.com/ggml-org/whisper.cpp/archive/refs/tags/${WHISPER_CPP_VERSION}.tar.gz" -o /tmp/whisper.cpp.tar.gz \
    && mkdir -p /tmp/whisper.cpp-src \
    && tar -xzf /tmp/whisper.cpp.tar.gz -C /tmp/whisper.cpp-src --strip-components=1 \
    && cmake -S /tmp/whisper.cpp-src -B /tmp/whisper.cpp-build \
        -DCMAKE_BUILD_TYPE=Release \
        -DWHISPER_BUILD_TESTS=OFF \
        -DWHISPER_BUILD_EXAMPLES=ON \
        -DGGML_NATIVE=OFF \
        -DGGML_CUDA=OFF \
    && cmake --build /tmp/whisper.cpp-build --config Release -j"$(nproc)" \
    && find /tmp/whisper.cpp-build -name '*.so*' -exec cp -P {} /usr/local/lib/ \; \
    && ldconfig \
    && install -m 0755 /tmp/whisper.cpp-build/bin/whisper-cli /usr/local/bin/whisper-cli \
    && rm -rf /tmp/whisper.cpp.tar.gz /tmp/whisper.cpp-src /tmp/whisper.cpp-build

RUN printf "date.timezone=America/Santiago\n" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /var/www/html
