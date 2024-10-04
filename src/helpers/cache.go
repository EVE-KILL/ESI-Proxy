package helpers

import (
	"crypto/sha256"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/patrickmn/go-cache"
)

type Cache struct {
	cache *cache.Cache
}

func NewCache(defaultExpiration, cleanupInterval time.Duration) *Cache {
	return &Cache{
		cache: cache.New(defaultExpiration, cleanupInterval),
	}
}

func (c *Cache) Set(key string, item CacheItem, ttl time.Duration) {
	c.cache.Set(key, item, ttl)
}

func (c *Cache) Get(key string) (CacheItem, bool) {
	item, found := c.cache.Get(key)
	if !found {
		return CacheItem{}, false
	}
	return item.(CacheItem), true
}

type CacheItem struct {
	Headers http.Header
	Status  int
	Body    []byte
}

func GenerateCacheKey(inputs ...string) string {
	combined := strings.Join(inputs, "")
	return fmt.Sprintf("%x", sha256.Sum256([]byte(combined)))
}
