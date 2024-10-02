package main

import (
	"bytes"
	"crypto/rand"
	"compress/gzip"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"github.com/patrickmn/go-cache"
	"golang.org/x/net/http2"
	"io"
	"io/ioutil"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

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
                -webkit-background-size: cover;
                -moz-background-size: cover;
                -o-background-size: cover;
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

type DialHomeResponse struct {
	ID    string `json:"id"`
	URL   string `json:"url"`
	Owner string `json:"owner"`
}

func generateName() string {
	name := os.Getenv("ESI_PROXY_NAME")
	if name == "" {
		nameBytes := make([]byte, 16)
		_, err := rand.Read(nameBytes)
		if err != nil {
			log.Fatal(err)
		}
		name = hex.EncodeToString(nameBytes)
	}
	return name
}

func dialHome(externalAddress string) {
	dialHomeURL := "https://eve-kill.com/api/proxy/add"

	data := DialHomeResponse{
		ID:    generateName(),
		URL:   externalAddress,
		Owner: os.Getenv("OWNER"),
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		log.Fatal(err)
	}

	resp, err := http.Post(dialHomeURL, "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		log.Fatal(err)
	}
	defer resp.Body.Close()

	log.Printf("DialHomeDevice response: %s", resp.Status)
}

func handleConnect(w http.ResponseWriter, r *http.Request) {
	destConn, err := net.Dial("tcp", r.Host)
	if err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	defer destConn.Close()

	w.WriteHeader(http.StatusOK)
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

	go transfer(destConn, clientConn)
	go transfer(clientConn, destConn)
}

func transfer(destination io.WriteCloser, source io.ReadCloser) {
	defer destination.Close()
	defer source.Close()
	io.Copy(destination, source)
}

var c = cache.New(5*time.Minute, 10*time.Minute)

func cacheKey(req *http.Request) string {
	return req.Method + ":" + req.URL.Path + "?" + req.URL.RawQuery
}

type CachedResponse struct {
	StatusCode int
	Header     http.Header
	Body       []byte
}

func cacheResponse(resp *http.Response) (*http.Response, error) {
	// Ensure only GET requests are cached
	if resp.Request.Method != http.MethodGet {
		return resp, nil
	}

	bodyBytes, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}
	resp.Body = ioutil.NopCloser(bytes.NewBuffer(bodyBytes))

	// Only cache if the status code is 200 or 304
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNotModified {
		return resp, nil
	}

	cacheDuration := 5 * time.Minute
	if cacheControl := resp.Header.Get("Cache-Control"); cacheControl != "" {
		if maxAgeIndex := strings.Index(cacheControl, "max-age="); maxAgeIndex != -1 {
			maxAgeStr := cacheControl[maxAgeIndex+8:]
			if commaIndex := strings.Index(maxAgeStr, ","); commaIndex != -1 {
				maxAgeStr = maxAgeStr[:commaIndex]
			}
			if maxAge, err := strconv.Atoi(maxAgeStr); err == nil {
				cacheDuration = time.Duration(maxAge) * time.Second
			}
		}
	}

	cachedResp := &CachedResponse{
		StatusCode: resp.StatusCode,
		Header:     resp.Header,
		Body:       bodyBytes,
	}

	c.Set(cacheKey(resp.Request), cachedResp, cacheDuration)
	log.Printf("Cached response for %s", cacheKey(resp.Request))
	return resp, nil
}

func getCachedResponse(req *http.Request) (*CachedResponse, bool) {
	// Only attempt to retrieve cache for GET requests
	if req.Method != http.MethodGet {
		return nil, false
	}

	if cachedResp, found := c.Get(cacheKey(req)); found {
		if resp, ok := cachedResp.(*CachedResponse); ok {
			log.Printf("Cache hit for %s", cacheKey(req))
			return resp, true
		}
	}
	log.Printf("Cache miss for %s", cacheKey(req))
	return nil, false
}

func main() {
	// Define command-line flags
	host := flag.String("host", "localhost", "Host to listen on")
	httpPort := flag.String("port", "9501", "HTTP port to listen on")
	flag.Parse()

	targetURL, err := url.Parse("https://esi.evetech.net/")
	if err != nil {
		log.Fatal(err)
	}

	proxy := httputil.NewSingleHostReverseProxy(targetURL)

	// Enable HTTP/2
	proxy.Transport = &http2.Transport{
		AllowHTTP: true,
	}

	client := &http.Client{
		Transport: &http2.Transport{
			AllowHTTP: true,
		},
	}
	proxy.Transport = client.Transport

	// Create a custom Director to modify the request
	originalDirector := proxy.Director
	proxy.Director = func(req *http.Request) {
		originalDirector(req)
		req.Host = targetURL.Host
		req.URL.Scheme = targetURL.Scheme
		req.URL.Host = targetURL.Host
		req.URL.Path = singleJoiningSlash(targetURL.Path, req.URL.Path)
		log.Printf("Request from %s to %s", req.RemoteAddr, req.URL.Path)
	}

	// Create a custom ModifyResponse function to log response details and perform rate limiting
	proxy.ModifyResponse = func(resp *http.Response) error {
		// Enable Gzip compression
		if strings.Contains(resp.Header.Get("Content-Encoding"), "gzip") {
			resp.Body, err = gzip.NewReader(resp.Body)
			if err != nil {
				return err
			}
		}

		esiErrorLimitRemaining := 100
		esiErrorLimitReset := 0

		if remainingHeader := resp.Header.Get("X-Esi-Error-Limit-Remain"); remainingHeader != "" {
			esiErrorLimitRemaining, _ = strconv.Atoi(remainingHeader)
		}

		if resetHeader := resp.Header.Get("X-Esi-Error-Limit-Reset"); resetHeader != "" {
			esiErrorLimitReset, _ = strconv.Atoi(resetHeader)
		}

		log.Printf("Response size: %d bytes", resp.ContentLength)
		log.Printf("ESI Error Limit Remaining: %d", esiErrorLimitRemaining)
		log.Printf("ESI Error Limit Reset: %d", esiErrorLimitReset)

		if esiErrorLimitRemaining < 100 {
			maxSleepTime := time.Duration(esiErrorLimitReset) * time.Second
			inverseFactor := float64(100-esiErrorLimitRemaining) / 100
			sleepTime := time.Duration(inverseFactor * inverseFactor * float64(maxSleepTime))

			if sleepTime < time.Millisecond {
				sleepTime = time.Millisecond
			}

			log.Printf("Sleeping for %s", sleepTime)
			time.Sleep(sleepTime)
		}

		// Cache the response only if the request method is GET
		if resp.Request.Method == http.MethodGet {
			_, err := cacheResponse(resp)
			if err != nil {
				log.Printf("Error caching response: %v", err)
			}
		}

		return nil
	}

	// Dial home if enabled
	dialHomeEnv := os.Getenv("DIAL_HOME")
	externalAddress := os.Getenv("EXTERNAL_ADDRESS")

	if strings.EqualFold(dialHomeEnv, "true") || dialHomeEnv == "1" {
		if externalAddress != "" {
			go dialHome(externalAddress)
		} else {
			log.Println("EXTERNAL_ADDRESS not set, skipping dial home")
		}
	}

	// Set timeouts for the HTTP server
	server := &http.Server{
		Addr: fmt.Sprintf("%s:%s", *host, *httpPort),
		Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.Method == http.MethodConnect {
				handleConnect(w, r)
			} else if r.Method == http.MethodGet && r.URL.Path == "/" {
				// Serve the information page for GET requests on the root path
				fmt.Fprint(w, infoPageTemplate)
			} else if r.URL.Path == "/healthz" {
				healthzHandler(w, r)
			} else if r.URL.Path == "/readyz" {
				readyzHandler(w, r)
			} else {
				// Only cache GET requests
				if r.Method == http.MethodGet {
					// Check cache before forwarding the request
					if cachedResp, found := getCachedResponse(r); found {
						for k, v := range cachedResp.Header {
							for _, vv := range v {
								w.Header().Add(k, vv)
							}
						}
						w.WriteHeader(cachedResp.StatusCode)
						w.Write(cachedResp.Body)
						return
					}
				}

				// Forward all other requests to the proxy
				proxy.ServeHTTP(w, r)
			}
		}),
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	log.Printf("Proxy server is running on http://%s", server.Addr)

	// Start HTTP server
	log.Fatal(server.ListenAndServe())
}

// singleJoiningSlash is a helper function to join URL paths
func singleJoiningSlash(a, b string) string {
	aslash := strings.HasSuffix(a, "/")
	bslash := strings.HasPrefix(b, "/")
	switch {
	case aslash && bslash:
		return a + b[1:]
	case !aslash && !bslash:
		return a + "/" + b
	}
	return a + b
}

// Health check endpoints
func healthzHandler(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("ok"))
}

func readyzHandler(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("ok"))
}
