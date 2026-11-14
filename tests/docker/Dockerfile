# syntax=docker/dockerfile:1
#
# The image the end-to-end runners (tests/run-*.sh) use as "spm2-e2e": PHP 8.2
# with the extensions Cacti 1.2 needs, rrdtool, the MariaDB client, and the
# SmokePing package for its Perl library and probe binaries.
#
#   docker build -t spm2-e2e tests/docker
#
# It is a test image and is never deployed. It runs as root on purpose: the
# runners bind-mount Cacti and SmokePing directories from the host, rely on
# read-only mounts and on files the poller creates there, and run the plugin's
# own privilege-sensitive checks. A single stage, because nothing is compiled
# here that a later stage could drop. The base is pinned by digest and kept
# current by Dependabot.
FROM php:8.2-cli@sha256:604de2f0cba9e9dde16cf717c2f03b0948c99949bdf7c11cdf76326e0da7d2ea

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		curl libgmp-dev libicu-dev libldap2-dev libpng-dev libsnmp-dev mariadb-client rrdtool smokeping snmp \
	&& docker-php-ext-install -j"$(nproc)" gd gmp intl ldap pcntl pdo_mysql snmp sockets \
	&& rm -rf /var/lib/apt/lists/*
