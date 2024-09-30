FROM library/golang:latest AS builder

LABEL org.opencontainers.image.source="https://github.com/EVE-KILL/ESI-Proxy"

# Run as root
USER root

# Set workdir
WORKDIR /app

# Copy the code
COPY . /app

# Build the application with static linking
RUN CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo -o proxy proxy.go

FROM library/alpine:latest

# Copy the application from the builder stage
COPY --from=builder /app/proxy /proxy

# Set the environment variables
EXPOSE 9501
CMD ["/proxy", "-host", "0.0.0.0", "-port", "9501"]
