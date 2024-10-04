package helpers

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log"
	"net/http"
	"os"
)

type DialHomeResponse struct {
	ID    string `json:"id"`
	URL   string `json:"url"`
	Owner string `json:"owner"`
}

func DialHome() {
	externalAddress := GetEnv("EXTERNAL_ADDRESS", "")
	owner := GetEnv("OWNER", "")
	if externalAddress == "" {
		log.Println("EXTERNAL_ADDRESS not set, skipping dial home")
		return
	}

	name := generateName()

	data := DialHomeResponse{
		ID:    name,
		URL:   externalAddress,
		Owner: owner,
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		log.Fatalf("Failed to marshal dial home data: %v", err)
	}

	resp, err := http.Post("https://eve-kill.com/api/proxy/add", "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		log.Fatalf("DialHome POST request failed: %v", err)
	}
	defer resp.Body.Close()

	log.Printf("DialHome response: %s", resp.Status)
}

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
