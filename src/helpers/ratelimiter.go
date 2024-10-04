package helpers

import (
	"sync"
	"time"
)

type RateLimiter struct {
	mu           sync.Mutex
	remaining    int
	reset        int
	backoffUntil time.Time
}

func NewRateLimiter() *RateLimiter {
	return &RateLimiter{
		remaining: 100, // Default to 100
		reset:     60,  // Default to 60 seconds
	}
}

func (rl *RateLimiter) Update(remaining, reset int) {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	rl.remaining = remaining
	rl.reset = reset
	if rl.remaining < 100 {
		inverseFactor := float64(100-rl.remaining) / 100
		maxSleepTime := time.Duration(rl.reset) * time.Second
		rl.backoffUntil = time.Now().Add(time.Duration(inverseFactor * inverseFactor * float64(maxSleepTime)))
	}
}

func (rl *RateLimiter) ShouldBackoff() time.Duration {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	if rl.backoffUntil.After(time.Now()) {
		return rl.backoffUntil.Sub(time.Now())
	}
	return 0
}
