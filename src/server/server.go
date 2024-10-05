package server

import (
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"time"

	"github.com/eve-kill/esi-proxy/endpoints"
	"github.com/eve-kill/esi-proxy/helpers"
	"github.com/eve-kill/esi-proxy/proxy"
)

func Test() {
	log.Println("Starting proxy server")
}

func setupServer() (*http.ServeMux, *httputil.ReverseProxy, *helpers.Cache) {
	// Create new router
	mux := http.NewServeMux()

	// Register health and ping endpoints
	mux.HandleFunc("/ping", endpoints.Ping)
	mux.HandleFunc("/healthz", endpoints.Healthz) // Liveness probe
	mux.HandleFunc("/readyz", endpoints.Readyz)   // Readiness probe

	// Handle .well-known requests by dropping them
	mux.HandleFunc("/.well-known/", func(w http.ResponseWriter, r *http.Request) {
		http.NotFound(w, r)
	})

	// Handle robots.txt
	mux.HandleFunc("/robots.txt", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("User-agent: *\nDisallow: /"))
	})

	// Handle favicon
	mux.HandleFunc("/favicon.ico", func(w http.ResponseWriter, r *http.Request) {
		http.NotFound(w, r)
	})

	// Set up proxy for all other routes
	upstreamURL, err := url.Parse("https://esi.evetech.net/")
	if err != nil {
		log.Fatalf("Error parsing upstream URL: %v", err)
	}

	proxyHandler := proxy.NewProxy(upstreamURL)

	// Initialize the Cache
	cache := helpers.NewCache(1*time.Hour, 1*time.Hour)

	mux.Handle("/", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// If the request path is exactly "/", handle it with the root endpoint
		if r.URL.Path == "/" {
			endpoints.Root(w, r)
			return
		}

		// Handle CONNECT method for tunneling
		if r.Method == http.MethodConnect {
			handleConnect(w, r)
			return
		}

		// Otherwise, handle it with the proxy
		proxy.RequestHandler(proxyHandler, upstreamURL, "/", cache)(w, r)
	}))

	return mux, proxyHandler, cache
}

// handleConnect handles the CONNECT method for tunneling
func handleConnect(w http.ResponseWriter, r *http.Request) {
	hijacker, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "Hijacking not supported", http.StatusInternalServerError)
		return
	}

	clientConn, _, err := hijacker.Hijack()
	if err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	defer clientConn.Close()

	serverConn, err := net.Dial("tcp", r.Host)
	if err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	defer serverConn.Close()

	clientConn.Write([]byte("HTTP/1.1 200 Connection Established\r\n\r\n"))

	go transfer(serverConn, clientConn)
	go transfer(clientConn, serverConn)
}

// transfer copies data between two connections
func transfer(destination net.Conn, source net.Conn) {
	defer destination.Close()
	defer source.Close()
	io.Copy(destination, source)
}

func StartServer() {
	mux, _, _ := setupServer()

	// Start server
	host := helpers.GetEnv("HOST", "0.0.0.0")
	port := helpers.GetEnv("PORT", "8080")

	log.Println("Proxy server started on http://" + host + ":" + port)
	log.Fatal(http.ListenAndServe(host+":"+port, mux))
}
