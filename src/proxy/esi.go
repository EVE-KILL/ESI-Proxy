package proxy

import (
	"bytes"
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
	body    bytes.Buffer
}

func (rc *responseCapture) Write(b []byte) (int, error) {
	return rc.body.Write(b)
}

func (rc *responseCapture) WriteHeader(statusCode int) {
	rc.status = statusCode
	rc.headers = rc.ResponseWriter.Header().Clone()
}

func RequestHandler(proxy *httputil.ReverseProxy, url *url.URL, endpoint string, cache *helpers.Cache) func(http.ResponseWriter, *http.Request) {
	return func(w http.ResponseWriter, r *http.Request) {
		cacheKey := helpers.GenerateCacheKey(r.URL.String(), r.Header.Get("Authorization"))
		if r.Method == http.MethodGet {
			if cachedResponse, found := cache.Get(cacheKey); found {
				// Return cached data if it exists
				for key, values := range cachedResponse.Headers {
					for _, value := range values {
						w.Header().Set(key, value)
					}
				}
				w.Header().Set("X-Proxy-Cache", "HIT")
				w.WriteHeader(cachedResponse.Status)
				w.Write(cachedResponse.Body)
				return
			}

			w.Header().Set("X-Proxy-Cache", "MISS")
		}

		fmt.Printf("[ PROXY SERVER ] Request received at %s at %s\n", r.URL, time.Now().UTC())

		// Modify the request to target the upstream server
		r.URL.Host = url.Host
		r.URL.Scheme = url.Scheme
		r.Header.Set("X-Forwarded-Host", r.Header.Get("Host"))
		r.Host = url.Host

		path := r.URL.Path
		r.URL.Path = strings.TrimLeft(path, endpoint)

		fmt.Printf("[ PROXY SERVER ] Proxying requests to %s at %s\n", r.URL, time.Now().UTC())

		// Capture the response from the upstream server
		rc := &responseCapture{ResponseWriter: w, headers: make(http.Header), status: http.StatusOK}
		proxy.ServeHTTP(rc, r)

		// Convert 304 Not Modified to 200 OK
		if rc.status == http.StatusNotModified {
			rc.status = http.StatusOK
		}

		// Set the captured headers
		for key, values := range rc.headers {
			for _, value := range values {
				w.Header().Set(key, value)
			}
		}

		// Write the correct status code
		w.WriteHeader(rc.status)

		// Write the captured body
		w.Write(rc.body.Bytes())

		// Cache the response if it's a 200 OK and the request method is GET
		if r.Method == http.MethodGet && rc.status == http.StatusOK {
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
								Body:    rc.body.Bytes(),
							}, ttl)
						}
					}
				}
			}
		}

		// Extract rate limiting headers
		limitRemain, _ := strconv.Atoi(rc.headers.Get("X-Esi-Error-Limit-Remain"))
		limitReset, _ := strconv.Atoi(rc.headers.Get("X-Esi-Error-Limit-Reset"))

		// Implement sleep logic based on error limit remaining
		sleepTimeInMicroseconds := 0
		if limitRemain < 100 {
			maxSleepTimeInMicroseconds := limitReset * 1000000
			inverseFactor := float64(100-limitRemain) / 100
			sleepTimeInMicroseconds = int(inverseFactor * inverseFactor * float64(maxSleepTimeInMicroseconds))
			if sleepTimeInMicroseconds < 1000 {
				sleepTimeInMicroseconds = 1000
			}
			time.Sleep(time.Duration(sleepTimeInMicroseconds) * time.Microsecond)
		}

		fmt.Printf("X-Esi-Error-Limit-Remain: %d, X-Esi-Error-Limit-Reset: %d, Sleep: %d ms\n", limitRemain, limitReset, sleepTimeInMicroseconds*1000)
	}
}
