package main

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/patrickmn/go-cache"
)

const (
	targetURLStr       = "https://esi.evetech.net/"
	infoPagePath       = "/"
	healthCheckPath    = "/healthz"
	readyCheckPath     = "/readyz"
	dialHomeURL        = "https://eve-kill.com/api/proxy/add"
	defaultCacheExpiry = 5 * time.Minute
	cleanupInterval    = 10 * time.Minute
)

// DialHomeResponse represents the payload sent to the dial home service.
type DialHomeResponse struct {
	ID    string `json:"id"`
	URL   string `json:"url"`
	Owner string `json:"owner"`
}

// CachedResponse represents a cached HTTP response.
type CachedResponse struct {
	StatusCode int
	Header     http.Header
	Body       []byte
}

// RateLimiter manages rate limiting based on ESI headers.
type RateLimiter struct {
	mu              sync.Mutex
	remaining       int
	reset           int
	lastUpdate      time.Time
	backoffDuration time.Duration
}

// Update updates the rate limiter based on ESI headers.
func (rl *RateLimiter) Update(remaining, reset int) {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	rl.remaining = remaining
	rl.reset = reset
	rl.lastUpdate = time.Now()
	if rl.remaining < 100 {
		maxSleepTime := time.Duration(rl.reset) * time.Second
		inverseFactor := float64(100-rl.remaining) / 100
		rl.backoffDuration = time.Duration(inverseFactor * inverseFactor * float64(maxSleepTime))
		if rl.backoffDuration < time.Millisecond {
			rl.backoffDuration = time.Millisecond
		}
	}
}

// ShouldBackoff determines if the proxy should back off based on rate limits.
func (rl *RateLimiter) ShouldBackoff() time.Duration {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	return rl.backoffDuration
}

// ProxyServer represents the API proxy server.
type ProxyServer struct {
	proxy       *httputil.ReverseProxy
	cache       *cache.Cache
	rateLimiter *RateLimiter
}

// NewProxyServer initializes and returns a new ProxyServer.
func NewProxyServer(target *url.URL) *ProxyServer {
	proxy := httputil.NewSingleHostReverseProxy(target)

	// Customize the transport for better performance and concurrency.
	proxy.Transport = &http.Transport{
		DialContext: (&net.Dialer{
			Timeout:   5 * time.Second,
			KeepAlive: 90 * time.Second,
		}).DialContext,
		TLSHandshakeTimeout:   5 * time.Second,
		MaxIdleConns:          100,
		MaxIdleConnsPerHost:   100,
		IdleConnTimeout:       90 * time.Second,
		TLSClientConfig:       nil,
		ExpectContinueTimeout: 1 * time.Second,
	}

	// Set the Director function to modify the request
	proxy.Director = func(req *http.Request) {
		req.Host = target.Host // Set the Host to the target's host
		req.URL.Scheme = target.Scheme
		req.URL.Host = target.Host
	}

	return &ProxyServer{
		proxy:       proxy,
		cache:       cache.New(defaultCacheExpiry, cleanupInterval),
		rateLimiter: &RateLimiter{},
	}
}

// ServeHTTP handles incoming HTTP requests.
func (ps *ProxyServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.Method == http.MethodConnect:
		ps.handleConnect(w, r)
	case r.URL.Path == infoPagePath && r.Method == http.MethodGet:
		ps.serveInfoPage(w, r)
	case r.URL.Path == healthCheckPath && r.Method == http.MethodGet:
		ps.serveHealthCheck(w, r)
	case r.URL.Path == readyCheckPath && r.Method == http.MethodGet:
		ps.serveReadyCheck(w, r)
	default:
		ps.handleProxy(w, r)
	}
}

// handleProxy handles the proxying of requests with caching and compression.
func (ps *ProxyServer) handleProxy(w http.ResponseWriter, r *http.Request) {
	if r.Method == http.MethodGet {
		if cachedResp, found := ps.getCachedResponse(r); found {
			ps.writeCachedResponse(w, cachedResp)
			return
		}
	}

	// Set ModifyResponse to handle rate limiting, compression, and caching
	ps.proxy.ModifyResponse = func(resp *http.Response) error {
		ps.handleRateLimiting(resp)

		// Compress the response
		ps.compressResponse(w, resp)

		// Cache the response only if it's a GET request with status 200 or 304
		if r.Method == http.MethodGet && (resp.StatusCode == http.StatusOK || resp.StatusCode == http.StatusNotModified) {
			ps.cacheResponse(r, resp)
		}

		return nil
	}

	// Handle errors from the proxy
	ps.proxy.ErrorHandler = func(w http.ResponseWriter, req *http.Request, err error) {
		http.Error(w, "Proxy Error: "+err.Error(), http.StatusBadGateway)
	}

	ps.proxy.ServeHTTP(w, r)
}

// handleConnect handles HTTP CONNECT method for tunneling.
func (ps *ProxyServer) handleConnect(w http.ResponseWriter, r *http.Request) {
	destConn, err := net.Dial("tcp", r.Host)
	if err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	defer destConn.Close()

	hj, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "Hijacking not supported", http.StatusInternalServerError)
		return
	}
	clientConn, _, err := hj.Hijack()
	if err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	defer clientConn.Close()

	clientConn.Write([]byte("HTTP/1.1 200 Connection Established\r\n\r\n"))
	go transfer(destConn, clientConn)
	go transfer(clientConn, destConn)
}

// serveInfoPage serves the informational HTML page.
func (ps *ProxyServer) serveInfoPage(w http.ResponseWriter, _ *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	fmt.Fprint(w, infoPageTemplate)
}

// serveHealthCheck responds with a simple "ok".
func (ps *ProxyServer) serveHealthCheck(w http.ResponseWriter, _ *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("ok"))
}

// serveReadyCheck responds with a simple "ok".
func (ps *ProxyServer) serveReadyCheck(w http.ResponseWriter, _ *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("ok"))
}

// handleRateLimiting updates and enforces rate limiting based on ESI headers.
func (ps *ProxyServer) handleRateLimiting(resp *http.Response) {
	remaining, _ := strconv.Atoi(resp.Header.Get("X-Esi-Error-Limit-Remain"))
	reset, _ := strconv.Atoi(resp.Header.Get("X-Esi-Error-Limit-Reset"))
	log.Printf("Rate limit remaining: %d, reset: %d", remaining, reset)
	ps.rateLimiter.Update(remaining, reset)

	if remaining < 100 {
		sleepDuration := ps.rateLimiter.ShouldBackoff()
		log.Printf("Rate limit exceeded. Sleeping for %s", sleepDuration)
		time.Sleep(sleepDuration)
		resp.Header.Add("X-Slept-By-Proxy", sleepDuration.String())
	}
}

// compressResponse is now a no-op function.
func (ps *ProxyServer) compressResponse(w http.ResponseWriter, resp *http.Response) {
	// No compression applied, simply return the response as is.
}

// cacheResponse caches the response for future GET requests.
func (ps *ProxyServer) cacheResponse(req *http.Request, resp *http.Response) {
	// Only cache responses with status 200 or 304
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNotModified {
		log.Printf("Response status %d not cacheable for %s", resp.StatusCode, req.URL.String())
		return
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Printf("Error reading response body for caching: %v", err)
		return
	}
	resp.Body = io.NopCloser(bytes.NewBuffer(body))

	cachedResp := &CachedResponse{
		StatusCode: resp.StatusCode,
		Header:     resp.Header.Clone(),
		Body:       body,
	}

	key := generateCacheKey(req)
	ps.cache.Set(key, cachedResp, defaultCacheExpiry)
	log.Printf("Cached response (status %d) for %s with key %s", resp.StatusCode, req.URL.String(), key)
}

// getCachedResponse retrieves a cached response if available.
func (ps *ProxyServer) getCachedResponse(req *http.Request) (*CachedResponse, bool) {
	key := generateCacheKey(req)
	if cached, found := ps.cache.Get(key); found {
		if cachedResp, ok := cached.(*CachedResponse); ok {
			log.Printf("Cache hit (status %d) for %s with key %s", cachedResp.StatusCode, req.URL.String(), key)
			return cachedResp, true
		}
		log.Printf("Cache entry found but type assertion failed for key %s", key)
	}
	log.Printf("Cache miss for %s with key %s", req.URL.String(), key)
	return nil, false
}

// writeCachedResponse writes the cached response to the client.
func (ps *ProxyServer) writeCachedResponse(w http.ResponseWriter, cachedResp *CachedResponse) {
	// Validate Content-Type before serving
	contentType := cachedResp.Header.Get("Content-Type")
	if !isValidContentType(contentType) {
		log.Printf("Invalid Content-Type '%s' for cached response, skipping cache", contentType)
		return
	}

	for k, values := range cachedResp.Header {
		for _, v := range values {
			w.Header().Add(k, v)
		}
	}
	w.WriteHeader(cachedResp.StatusCode)
	_, err := w.Write(cachedResp.Body)
	if err != nil {
		log.Printf("Error writing cached response body: %v", err)
	}
}

// isValidContentType validates the Content-Type of the cached response
func isValidContentType(contentType string) bool {
	validTypes := []string{
		"application/json",
	}

	for _, vt := range validTypes {
		if strings.Contains(contentType, vt) {
			return true
		}
	}
	return false
}

// generateCacheKey creates a unique cache key based on the request.
func generateCacheKey(req *http.Request) string {
	// Include the scheme and host to ensure uniqueness across different hosts
	key := fmt.Sprintf("%s://%s%s?%s", req.URL.Scheme, req.Host, req.URL.Path, req.URL.RawQuery)

	// Incorporate relevant headers that might affect the response
	relevantHeaders := []string{"Authorization", "Accept", "Accept-Encoding"}
	for _, header := range relevantHeaders {
		if value := req.Header.Get(header); value != "" {
			key += fmt.Sprintf(":%s=%s", header, value)
		}
	}

	// Hash the key to create a fixed-length unique identifier
	hash := sha256.Sum256([]byte(key))
	return hex.EncodeToString(hash[:])
}

// dialHome registers the proxy with the dial home service.
func dialHome(externalAddress string, name string, owner string) {
	data := DialHomeResponse{
		ID:    name,
		URL:   externalAddress,
		Owner: owner,
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		log.Fatalf("Failed to marshal dial home data: %v", err)
	}

	resp, err := http.Post(dialHomeURL, "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		log.Fatalf("DialHome POST request failed: %v", err)
	}
	defer resp.Body.Close()

	log.Printf("DialHome response: %s", resp.Status)
}

// generateName generates a unique name for the proxy.
func generateName() string {
	name := os.Getenv("ESI_PROXY_NAME")
	if name == "" {
		nameBytes := make([]byte, 16)
		if _, err := rand.Read(nameBytes); err != nil {
			log.Fatalf("Failed to generate proxy name: %v", err)
		}
		name = hex.EncodeToString(nameBytes)
	}
	return name
}

// transfer copies data between source and destination.
func transfer(destination io.WriteCloser, source io.ReadCloser) {
	defer destination.Close()
	defer source.Close()
	io.Copy(destination, source)
}

func main() {
	// Command-line flags
	host := flag.String("host", "localhost", "Host to listen on")
	port := flag.String("port", "9501", "Port to listen on")
	flag.Parse()

	targetURL, err := url.Parse(targetURLStr)
	if err != nil {
		log.Fatalf("Failed to parse target URL: %v", err)
	}

	proxyServer := NewProxyServer(targetURL)

	// Register dial home if enabled
	if strings.EqualFold(os.Getenv("DIAL_HOME"), "true") || os.Getenv("DIAL_HOME") == "1" {
		externalAddress := os.Getenv("EXTERNAL_ADDRESS")
		owner := os.Getenv("OWNER")
		if externalAddress != "" {
			go dialHome(externalAddress, generateName(), owner)
		} else {
			log.Println("EXTERNAL_ADDRESS not set, skipping dial home")
		}
	}

	server := &http.Server{
		Addr:         fmt.Sprintf("%s:%s", *host, *port),
		Handler:      proxyServer,
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	// Periodically log cache stats
	go func() {
		ticker := time.NewTicker(10 * time.Minute)
		defer ticker.Stop()
		for range ticker.C {
			stats := proxyServer.cache.ItemCount()
			log.Printf("Cache currently holds %d items", stats)
		}
	}()

	log.Printf("Proxy server is running on http://%s", server.Addr)
	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("Server failed: %v", err)
	}
}

// infoPageTemplate is the HTML template for the information page.
const infoPageTemplate = `<!DOCTYPE html>
<html lang="en">
	<head>
		<title>EVE-Online API Proxy</title>
		<style>
			body {
				font-family: Arial, sans-serif;
				margin: 0;
				padding: 0;
				display: flex;
				justify-content: center;
				align-items: center;
				height: 100vh;
				background: url(https://esi.evetech.net/ui/background.jpg) no-repeat center center fixed;
				background-size: cover;
				background-color: black;
			}
			.container {
				text-align: center;
				background-color: rgba(255, 255, 255, 0.8);
				padding: 20px;
				border-radius: 10px;
			}
			a {
				color: #0066cc;
				text-decoration: none;
			}
			a:hover {
				text-decoration: underline;
			}
		</style>
	</head>
	<body>
		<div class="container">
			<h1>Welcome to the ESI API Proxy</h1>
			<p>This site serves as an API Proxy for EVE-Online.</p>
			<p>For all API information, please refer to the official documentation at <a href="https://esi.evetech.net" target="_blank">https://esi.evetech.net</a></p>
			<br/>
			<p>In general all requests ESI can serve, this can also serve</p>
		</div>
	</body>
</html>`
