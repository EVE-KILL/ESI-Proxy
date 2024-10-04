package endpoints

import (
	"net/http"
	"text/template"
)

func Root(w http.ResponseWriter, r *http.Request) {
	// Reply back with the index.html file from the templates folder
	tmpl := template.Must(template.ParseFiles("templates/index.html"))

	// Execute the template and write directly to the response writer
	err := tmpl.Execute(w, nil)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
}
