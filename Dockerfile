FROM php:8.3-cli-alpine AS base

RUN set -ex \
 && apk add --update --no-cache \
    autoconf \
    bash \
    curl \
    g++ \
    git \
    jq \
    libtool \
    make \
    openssh-client \
    tini \
 && eval $(ssh-agent -s) \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && rm -rf /var/cache/apk/*

FROM base AS docker

RUN set -ex \
 && apk add --update --no-cache \
    docker \
    findmnt \
    sshfs \
    yaml-dev \
 && curl -L https://github.com/docker/compose/releases/download/v2.35.1/docker-compose-linux-$(uname -m) > /usr/bin/docker-compose \
 && chmod +x /usr/bin/docker-compose \
 && pecl install yaml \
 && docker-php-ext-enable yaml \
 && docker-php-source delete \
 && rm -rf /var/cache/apk/*

FROM docker AS releaser

ARG IO_RELEASER_VERSION

ENV IO_RELEASER_VERSION=$IO_RELEASER_VERSION

COPY . /app/

WORKDIR /app

RUN set -ex \
 && cp /app/job/deploy /etc/periodic/15min \
 && cp /app/job/backup /etc/periodic/daily/ \
 && cp /app/job/sanitize /etc/periodic/monthly/ \
 && chmod a+x /etc/periodic/15min/* \
 && chmod a+x /etc/periodic/daily/* \
 && chmod a+x /etc/periodic/monthly/* \
 && chmod a+x /app/bin/* \
 && composer install --no-interaction -d /app

ENV PATH=/app/bin:$PATH

ENTRYPOINT ["/sbin/tini", "--"]

CMD ["/usr/sbin/crond", "-f"]
