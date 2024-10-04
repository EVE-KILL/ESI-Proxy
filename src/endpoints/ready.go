package endpoints

import "net/http"

// Readyz is used for the readiness probe
func Readyz(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("Ready"))
}
