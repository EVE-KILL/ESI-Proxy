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

func setupServer() (*http.ServeMux, *httputil.ReverseProxy, *helpers.RateLimiter, *helpers.Cache, *helpers.RequestQueue) {
	// Create new router
	mux := http.NewServeMux()

	// Register health and ping endpoints
	mux.HandleFunc("/ping", endpoints.Ping)
	mux.HandleFunc("/healthz", endpoints.Healthz) // Liveness probe
	mux.HandleFunc("/readyz", endpoints.Readyz)   // Readiness probe

	// Set up proxy for all other routes
	upstreamURL, err := url.Parse("https://esi.evetech.net/")
	if err != nil {
		log.Fatalf("Error parsing upstream URL: %v", err)
	}

	proxyHandler := proxy.NewProxy(upstreamURL)

	// Initialize the RateLimiter with default values
	rateLimiter := helpers.NewRateLimiter()

	// Initialize the Cache
	cache := helpers.NewCache(5*time.Minute, 1*time.Hour)

	// Initialize the Request Queue
	requestQueue := helpers.NewRequestQueue() // Adjust size as needed

	mux.Handle("/", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// If the request path is exactly "/", handle it with the root endpoint
		if r.URL.Path == "/" {
			endpoints.Root(w, r)
			return
		}
		// Otherwise, handle it with the proxy
		proxy.RequestHandler(proxyHandler, upstreamURL, "/", rateLimiter, cache, requestQueue)(w, r)
	}))

	return mux, proxyHandler, rateLimiter, cache, requestQueue
}

func StartServer() {
	mux, _, _, _, requestQueue := setupServer()

	// Start processing the request queue
	go requestQueue.ProcessQueue(func(req helpers.QueuedRequest) {
		// Implement the logic to process each queued request
		// This can be similar to the logic in the RequestHandler function
	})

	// Dial home
	go helpers.DialHome()

	// Start server
	host := helpers.GetEnv("HOST", "0.0.0.0")
	port := helpers.GetEnv("PORT", "8080")

	log.Println("Proxy server started on http://" + host + ":" + port)
	log.Fatal(http.ListenAndServe(host+":"+port, mux))
}
