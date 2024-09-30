package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
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
	namePath := "/tmp/esi-proxy.name"

	if _, err := os.Stat(namePath); err == nil {
		data, err := ioutil.ReadFile(namePath)
		if err == nil {
			return string(data)
		}
	}

	name := make([]byte, 16)
	_, err := rand.Read(name)
	if err != nil {
		log.Fatal(err)
	}

	hexName := hex.EncodeToString(name)
	err = ioutil.WriteFile(namePath, []byte(hexName), 0644)
	if err != nil {
		log.Fatal(err)
	}

	return hexName
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
			maxSleepTimeInMicroseconds := time.Duration(esiErrorLimitReset) * time.Second
			inverseFactor := float64(100-esiErrorLimitRemaining) / 100
			sleepTimeInMicroseconds := time.Duration(inverseFactor * inverseFactor * float64(maxSleepTimeInMicroseconds))

			if sleepTimeInMicroseconds < time.Millisecond {
				sleepTimeInMicroseconds = time.Millisecond
			}

			log.Printf("Sleeping for %s", sleepTimeInMicroseconds)
			time.Sleep(sleepTimeInMicroseconds)
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

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodConnect {
			handleConnect(w, r)
		} else if r.Method == http.MethodGet && r.URL.Path == "/" {
			// Serve the information page for GET requests on the root path
			fmt.Fprint(w, infoPageTemplate)
		} else {
			// Forward all other requests to the proxy
			proxy.ServeHTTP(w, r)
		}
	})

	httpAddress := fmt.Sprintf("%s:%s", *host, *httpPort)
	log.Printf("Proxy server is running on http://%s", httpAddress)

	// Start HTTP server
	log.Fatal(http.ListenAndServe(httpAddress, nil))
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
