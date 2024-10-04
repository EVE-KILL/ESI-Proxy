package proxy

import (
	"fmt"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/eve-kill/esi-proxy/helpers"
)

func NewProxy(targetURL *url.URL) *httputil.ReverseProxy {
	proxy := httputil.NewSingleHostReverseProxy(targetURL)
	proxy.Transport = &http.Transport{
		DialContext: (&net.Dialer{
			Timeout:   5 * time.Second,
			KeepAlive: 90 * time.Second,
		}).DialContext,
		TLSHandshakeTimeout: 5 * time.Second,
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 100,
		IdleConnTimeout:     90 * time.Second,
	}

	return proxy
}

type responseCapture struct {
	http.ResponseWriter
	headers http.Header
	status  int
	body    []byte
}

func (rc *responseCapture) Write(b []byte) (int, error) {
	rc.body = append(rc.body, b...)
	return rc.ResponseWriter.Write(b)
}

func (rc *responseCapture) WriteHeader(statusCode int) {
	rc.status = statusCode
	rc.headers = rc.ResponseWriter.Header().Clone() // Clone headers to capture them
	rc.ResponseWriter.WriteHeader(statusCode)
}

func RequestHandler(proxy *httputil.ReverseProxy, url *url.URL, endpoint string, rateLimiter *helpers.RateLimiter, cache *helpers.Cache, requestQueue *helpers.RequestQueue) func(http.ResponseWriter, *http.Request) {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			proxy.ServeHTTP(w, r)
			return
		}

		cacheKey := helpers.GenerateCacheKey(r.URL.String(), r.Header.Get("Authorization"))
		if cachedResponse, found := cache.Get(cacheKey); found {
			// Always return cached data if it exists
			for key, values := range cachedResponse.Headers {
				for _, value := range values {
					w.Header().Add(key, value)
				}
			}
			w.Header().Set("X-Proxy-Cache", "HIT")
			w.WriteHeader(cachedResponse.Status)
			w.Write(cachedResponse.Body)
			return
		}

		w.Header().Set("X-Proxy-Cache", "MISS")

		if backoff := rateLimiter.ShouldBackoff(); backoff > 0 {
			requestQueue.Enqueue(w, r)
			return
		}

		fmt.Printf("[ PROXY SERVER ] Request received at %s at %s\n", r.URL, time.Now().UTC())

		r.URL.Host = url.Host
		r.URL.Scheme = url.Scheme
		r.Header.Set("X-Forwarded-Host", r.Header.Get("Host"))
		r.Host = url.Host

		path := r.URL.Path
		r.URL.Path = strings.TrimLeft(path, endpoint)

		fmt.Printf("[ PROXY SERVER ] Proxying requests to %s at %s\n", r.URL, time.Now().UTC())

		rc := &responseCapture{ResponseWriter: w, headers: make(http.Header)}
		proxy.ServeHTTP(rc, r)

		// Cache the response if it's a 200 OK or 304 Not Modified
		if rc.status == http.StatusOK || rc.status == http.StatusNotModified {
			dateHeader := rc.headers.Get("Date")
			expiresHeader := rc.headers.Get("Expires")
			if dateHeader != "" && expiresHeader != "" {
				date, err := time.Parse(time.RFC1123, dateHeader)
				if err == nil {
					expires, err := time.Parse(time.RFC1123, expiresHeader)
					if err == nil {
						ttl := expires.Sub(date)
						if ttl > time.Second {
							cache.Set(cacheKey, helpers.CacheItem{
								Headers: rc.headers,
								Status:  rc.status,
								Body:    rc.body,
							}, ttl)
						}
					}
				}
			}
		}

		// Return the status and body from upstream
		//w.WriteHeader(rc.status)
		//w.Write(rc.body)

		limitRemain, _ := strconv.Atoi(rc.headers.Get("X-Esi-Error-Limit-Remain"))
		limitReset, _ := strconv.Atoi(rc.headers.Get("X-Esi-Error-Limit-Reset"))

		rateLimiter.Update(limitRemain, limitReset)

		fmt.Printf("X-Esi-Error-Limit-Remain: %d, X-Esi-Error-Limit-Reset: %d\n", limitRemain, limitReset)
	}
}
