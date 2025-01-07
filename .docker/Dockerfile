FROM oven/bun:latest

LABEL org.opencontainers.image.source="https://github.com/EVE-KILL/ESI-Proxy"

# Install CA certificates
RUN apt-get update && apt-get install -y ca-certificates && rm -rf /var/lib/apt/lists/*

# Set workdir
WORKDIR /app

# Copy the source code and templates
COPY . /app

RUN bun install --production

EXPOSE 3006

CMD [ "bun", "--bun", "run", "/app/server.ts" ]
