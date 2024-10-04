FROM library/golang:latest AS builder

LABEL org.opencontainers.image.source="https://github.com/EVE-KILL/ESI-Proxy"

# Run as root
USER root

# Set workdir
WORKDIR /app

# Copy the code
COPY ./src /app

# Build the application with static linking
RUN CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo

FROM library/alpine:latest

# Copy the application from the builder stage
COPY --from=builder /app/esi-proxy /esi-proxy

# Set the environment variables
EXPOSE 9501
ENV PORT=9501
ENV HOST=0.0.0.0
CMD ["/esi-proxy"]
