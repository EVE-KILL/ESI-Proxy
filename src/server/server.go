package server

import (
	"log"
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

func setupServer() (*http.ServeMux, *httputil.ReverseProxy, *helpers.RateLimiter, *helpers.Cache) {
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

	// Set up proxy for all other routes
	upstreamURL, err := url.Parse("https://esi.evetech.net/")
	if err != nil {
		log.Fatalf("Error parsing upstream URL: %v", err)
	}

	proxyHandler := proxy.NewProxy(upstreamURL)

	// Initialize the RateLimiter with default values
	rateLimiter := helpers.NewRateLimiter()

	// Initialize the Cache
	cache := helpers.NewCache(1*time.Hour, 1*time.Hour)

	mux.Handle("/", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// If the request path is exactly "/", handle it with the root endpoint
		if r.URL.Path == "/" {
			endpoints.Root(w, r)
			return
		}
		// Otherwise, handle it with the proxy
		proxy.RequestHandler(proxyHandler, upstreamURL, "/", rateLimiter, cache)(w, r)
	}))

	return mux, proxyHandler, rateLimiter, cache
}

func StartServer() {
	mux, _, _, _ := setupServer()

	// Start server
	host := helpers.GetEnv("HOST", "0.0.0.0")
	port := helpers.GetEnv("PORT", "8080")

	log.Println("Proxy server started on http://" + host + ":" + port)
	log.Fatal(http.ListenAndServe(host+":"+port, mux))
}
