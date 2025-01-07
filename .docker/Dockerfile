FROM oven/bun:latest

LABEL org.opencontainers.image.source="https://github.com/EVE-KILL/ESI-Proxy"

# Set workdir
WORKDIR /app

# Copy the source code and templates
COPY . /app

RUN \
    bun install --production

EXPOSE 3006

CMD [ "bun", "--bun", "run", "/app/server.ts" ]
