package helpers

import (
	"net/http"
	"sync"
)

type QueuedRequest struct {
	ResponseWriter http.ResponseWriter
	Request        *http.Request
}

type RequestQueue struct {
	queue []QueuedRequest
	mu    sync.Mutex
	cond  *sync.Cond
}

func NewRequestQueue() *RequestQueue {
	rq := &RequestQueue{
		queue: make([]QueuedRequest, 0),
	}
	rq.cond = sync.NewCond(&rq.mu)
	return rq
}

func (rq *RequestQueue) Enqueue(w http.ResponseWriter, r *http.Request) {
	rq.mu.Lock()
	defer rq.mu.Unlock()
	rq.queue = append(rq.queue, QueuedRequest{ResponseWriter: w, Request: r})
	rq.cond.Signal() // Notify a waiting goroutine that a new request is available
}

func (rq *RequestQueue) Dequeue() QueuedRequest {
	rq.mu.Lock()
	defer rq.mu.Unlock()
	for len(rq.queue) == 0 {
		rq.cond.Wait() // Wait for a request to be enqueued
	}
	req := rq.queue[0]
	rq.queue = rq.queue[1:]
	return req
}

func (rq *RequestQueue) ProcessQueue(processFunc func(QueuedRequest)) {
	for {
		req := rq.Dequeue()
		processFunc(req)
	}
}
