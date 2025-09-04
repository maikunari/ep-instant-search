(function($) {
    'use strict';
    
    // Bail early if config not available
    if (typeof ep_instant_search === 'undefined') {
        return;
    }
    
    // Security: Validate configuration
    const config = {
        ajax_url: ep_instant_search.ajax_url || '',
        rest_url: ep_instant_search.rest_url || '/wp-json/elasticpress/v1/search',
        nonce: ep_instant_search.nonce || '',
        min_chars: Math.max(2, Math.min(10, parseInt(ep_instant_search.min_chars) || 2)),
        max_results: Math.max(1, Math.min(50, parseInt(ep_instant_search.max_results) || 8)),
        show_price: ep_instant_search.show_price === 'yes',
        show_image: ep_instant_search.show_image === 'yes',
        show_sku: ep_instant_search.show_sku === 'yes',
        search_delay: Math.max(100, Math.min(2000, parseInt(ep_instant_search.search_delay) || 300)),
        selectors: ep_instant_search.selectors || '.search-field, input[name="s"]',
        debug: ep_instant_search.debug === true,
        request_timeout: 5000, // 5 second timeout
        max_cache_size: 50, // Maximum cached searches
        rate_limit_window: 1000, // 1 second
        rate_limit_max: 5 // Max 5 requests per second
    };
    
    // Rate limiting
    const rateLimiter = {
        requests: [],
        check: function() {
            const now = Date.now();
            this.requests = this.requests.filter(time => now - time < config.rate_limit_window);
            if (this.requests.length >= config.rate_limit_max) {
                return false;
            }
            this.requests.push(now);
            return true;
        }
    };
    
    // Debug logging helper
    function log() {
        if (config.debug && console && console.log) {
            console.log.apply(console, ['[EP Instant Search]'].concat(Array.prototype.slice.call(arguments)));
        }
    }
    
    // Enhanced HTML escaping to prevent XSS
    function escapeHtml(text) {
        if (typeof text !== 'string') {
            return '';
        }
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        
        return text.replace(/[&<>"'`=\/]/g, function(char) {
            return map[char];
        });
    }
    
    // Sanitize search input
    function sanitizeSearchTerm(term) {
        if (typeof term !== 'string') {
            return '';
        }
        
        // Remove any HTML tags and dangerous characters
        term = term.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
        term = term.replace(/<[^>]+>/g, '');
        term = term.replace(/[<>\"']/g, '');
        
        // Limit length to prevent DoS
        term = term.substring(0, 100);
        
        return term.trim();
    }
    
    // Safe property access with validation
    function safeGet(obj, path, defaultValue = '') {
        if (!obj || typeof obj !== 'object') {
            return defaultValue;
        }
        
        const keys = path.split('.');
        let result = obj;
        
        for (const key of keys) {
            if (result && typeof result === 'object' && key in result) {
                result = result[key];
            } else {
                return defaultValue;
            }
        }
        
        return result !== null && result !== undefined ? result : defaultValue;
    }
    
    // Create safe DOM element
    function createElement(tag, className, content) {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (content) {
            element.textContent = content; // Always use textContent for safety
        }
        return element;
    }
    
    // LRU Cache with size limit
    const cache = {
        data: new Map(),
        maxSize: config.max_cache_size,
        
        get(key) {
            if (this.data.has(key)) {
                // Move to end (most recently used)
                const value = this.data.get(key);
                this.data.delete(key);
                this.data.set(key, value);
                return value;
            }
            return null;
        },
        
        set(key, value) {
            // Delete oldest if at capacity
            if (this.data.size >= this.maxSize && !this.data.has(key)) {
                const firstKey = this.data.keys().next().value;
                this.data.delete(firstKey);
            }
            this.data.set(key, value);
        },
        
        clear() {
            this.data.clear();
        }
    };
    
    // Active request tracking
    let abortController = null;
    const activeRequests = new WeakMap();
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            const later = function() {
                timeout = null;
                func.apply(context, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            return timeout;
        };
    }
    
    // Initialize on DOM ready
    $(document).ready(function() {
        log('Initializing with secure configuration...');
        
        // Validate selectors to prevent selector injection
        let selectors;
        try {
            selectors = config.selectors;
            // Test if selector is valid
            $(selectors);
        } catch (e) {
            console.error('Invalid selector configuration');
            return;
        }
        
        const $searchInputs = $(selectors);
        
        log('Found', $searchInputs.length, 'search input(s)');
        
        if ($searchInputs.length === 0) {
            return;
        }
        
        // Initialize each search input
        $searchInputs.each(function() {
            initSearchInput($(this));
        });
        
        // Global click handler for closing dropdowns
        $(document).on('click.ep_instant_search', function(e) {
            if (!$(e.target).closest('.ep-instant-results, ' + selectors).length) {
                $('.ep-instant-results.active').removeClass('active');
            }
        });
        
        // Cleanup on page unload
        $(window).on('beforeunload', function() {
            if (abortController) {
                abortController.abort();
            }
            cache.clear();
        });
    });
    
    function initSearchInput($input) {
        // Skip if already initialized
        if ($input.data('ep-instant-search-init')) {
            return;
        }
        
        $input.data('ep-instant-search-init', true);
        
        const $form = $input.closest('form');
        const $parent = $form.length ? $form : $input.parent();
        const $resultsBox = $('<div class="ep-instant-results" aria-live="polite" role="listbox"></div>');
        let lastQuery = '';
        let currentRequest = null;
        
        // Setup positioning
        $parent.css('position', 'relative');
        $parent.append($resultsBox);
        
        // Create debounced search function
        const debouncedSearch = debounce(function(query) {
            performSearch(query);
        }, config.search_delay);
        
        // Bind events with namespacing for cleanup
        $input
            .attr('autocomplete', 'off')
            .attr('aria-autocomplete', 'list')
            .attr('aria-controls', 'ep-results-' + $input.index())
            .on('input.ep_instant_search', handleInput)
            .on('keydown.ep_instant_search', handleKeydown)
            .on('focus.ep_instant_search', handleFocus)
            .on('blur.ep_instant_search', handleBlur);
        
        $resultsBox.attr('id', 'ep-results-' + $input.index());
        
        function handleInput(e) {
            // Sanitize input
            let query = sanitizeSearchTerm($input.val());
            
            // Cancel any pending search
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            
            // Abort previous AJAX request if using fallback
            if (currentRequest && currentRequest.abort) {
                currentRequest.abort();
                currentRequest = null;
            }
            
            // Clear if too short
            if (query.length < config.min_chars) {
                $resultsBox.removeClass('active').empty();
                lastQuery = '';
                return;
            }
            
            // Skip if same
            if (query === lastQuery) {
                return;
            }
            
            lastQuery = query;
            
            // Check rate limit
            if (!rateLimiter.check()) {
                log('Rate limit exceeded, skipping request');
                return;
            }
            
            // Show loading spinner
            showLoading();
            
            // Perform debounced search
            debouncedSearch(query);
        }
        
        function handleKeydown(e) {
            switch(e.keyCode) {
                case 27: // ESC
                    $resultsBox.removeClass('active');
                    $input.blur();
                    e.preventDefault();
                    break;
                case 38: // Up arrow
                case 40: // Down arrow
                    if ($resultsBox.hasClass('active')) {
                        navigateResults(e.keyCode === 40 ? 'down' : 'up');
                        e.preventDefault();
                    }
                    break;
                case 13: // Enter
                    if ($resultsBox.hasClass('active')) {
                        const $selected = $resultsBox.find('.selected a');
                        if ($selected.length) {
                            window.location.href = $selected.attr('href');
                            e.preventDefault();
                        }
                    }
                    break;
            }
        }
        
        function handleFocus() {
            if (lastQuery && $resultsBox.html()) {
                $resultsBox.addClass('active');
            }
        }
        
        function handleBlur() {
            // Delay to allow clicks on results
            setTimeout(function() {
                if (!$input.is(':focus')) {
                    $resultsBox.removeClass('active');
                }
            }, 200);
        }
        
        function navigateResults(direction) {
            const $items = $resultsBox.find('li');
            const $current = $items.filter('.selected');
            let index = $items.index($current);
            
            $items.removeClass('selected');
            
            if (direction === 'down') {
                index = index < $items.length - 1 ? index + 1 : 0;
            } else {
                index = index > 0 ? index - 1 : $items.length - 1;
            }
            
            $items.eq(index).addClass('selected');
        }
        
        function showLoading() {
            const loadingDiv = createElement('div', 'loading');
            loadingDiv.setAttribute('role', 'status');
            
            const spinner = createElement('div', 'spinner');
            const text = createElement('span', '', 'Loading results...');
            
            loadingDiv.appendChild(spinner);
            loadingDiv.appendChild(text);
            
            $resultsBox.empty().append(loadingDiv).addClass('active');
        }
        
        function performSearch(query) {
            log('Searching for:', query);
            
            // Check cache first
            const cacheKey = 'search_' + query;
            const cachedResults = cache.get(cacheKey);
            
            if (cachedResults) {
                log('Using cached results');
                displayResults(cachedResults);
                return;
            }
            
            // Validate nonce exists
            if (!config.nonce) {
                log('Warning: No nonce available for REST API');
                performSearchAjaxFallback(query);
                return;
            }
            
            // Cancel previous request if still pending
            if (abortController) {
                abortController.abort();
            }
            
            // Create new AbortController with timeout
            abortController = new AbortController();
            const timeoutId = setTimeout(() => {
                if (abortController) {
                    abortController.abort();
                }
            }, config.request_timeout);
            
            // Prepare REST API request data
            const requestData = {
                s: query,
                post_type: 'product',
                per_page: config.max_results,
                search_fields: [
                    'post_title',
                    'post_content', 
                    'meta._sku',
                    'meta._variations_skus'
                ]
            };
            
            // Build REST API URL
            const restUrl = config.rest_url;
            
            log('Making REST API request to:', restUrl);
            
            // Use fetch for REST API call
            fetch(restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(requestData),
                signal: abortController.signal,
                credentials: 'same-origin'
            })
            .then(function(response) {
                clearTimeout(timeoutId);
                
                // Check if response is ok
                if (!response.ok) {
                    throw new Error('Network response error');
                }
                
                // Verify content type
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Invalid content type');
                }
                
                return response.json();
            })
            .then(function(data) {
                log('REST API response received');
                
                // Validate response structure
                if (!data || typeof data !== 'object') {
                    throw new Error('Invalid response format');
                }
                
                // Handle different response structures safely
                let results = [];
                
                if (Array.isArray(data)) {
                    results = data;
                } else if (data.data && Array.isArray(data.data)) {
                    results = data.data;
                } else if (data.posts && Array.isArray(data.posts)) {
                    results = data.posts;
                } else {
                    console.error('Unexpected response structure');
                    showError('No results found');
                    return;
                }
                
                // Transform and validate results
                const transformedResults = [];
                
                for (let i = 0; i < Math.min(results.length, config.max_results); i++) {
                    const item = results[i];
                    
                    if (!item || typeof item !== 'object') {
                        continue;
                    }
                    
                    try {
                        const transformed = {
                            id: parseInt(safeGet(item, 'id', 0)) || parseInt(safeGet(item, 'ID', 0)) || 0,
                            title: String(safeGet(item, 'title.rendered', safeGet(item, 'post_title', safeGet(item, 'title', 'Untitled')))),
                            url: String(safeGet(item, 'link', safeGet(item, 'permalink', '#'))),
                            type: String(safeGet(item, 'type', safeGet(item, 'post_type', 'product'))),
                            price: String(safeGet(item, 'meta.price', safeGet(item, 'price', ''))),
                            price_html: String(safeGet(item, 'meta.price_html', safeGet(item, 'price_html', ''))),
                            image: String(safeGet(item, 'featured_media_url', safeGet(item, 'thumbnail', safeGet(item, 'image', '')))),
                            sku: String(safeGet(item, 'meta._sku', safeGet(item, 'sku', ''))),
                            in_stock: safeGet(item, 'meta.in_stock', true) !== false,
                            excerpt: String(safeGet(item, 'excerpt.rendered', safeGet(item, 'excerpt', '')))
                        };
                        
                        // Validate URL
                        if (!transformed.url.match(/^(https?:\/\/|\/|#)/)) {
                            transformed.url = '#';
                        }
                        
                        transformedResults.push(transformed);
                    } catch (e) {
                        log('Error transforming result item:', e);
                    }
                }
                
                // Cache results
                cache.set(cacheKey, transformedResults);
                
                displayResults(transformedResults);
                abortController = null;
            })
            .catch(function(error) {
                clearTimeout(timeoutId);
                
                // Don't show error for aborted requests
                if (error.name === 'AbortError') {
                    log('Request aborted');
                    return;
                }
                
                log('REST API error, falling back to AJAX');
                
                // Fallback to AJAX
                performSearchAjaxFallback(query);
            });
        }
        
        function performSearchAjaxFallback(query) {
            if (!config.ajax_url) {
                showError('Search configuration error');
                return;
            }
            
            const requestData = {
                action: 'ep_instant_search',
                q: query,
                nonce: config.nonce // Include nonce for AJAX too
            };
            
            currentRequest = $.ajax({
                url: config.ajax_url,
                type: 'GET',
                dataType: 'json',
                timeout: config.request_timeout,
                data: requestData,
                success: function(response) {
                    log('AJAX fallback response received');
                    
                    if (!response || !Array.isArray(response)) {
                        showError('Invalid response');
                        return;
                    }
                    
                    // Validate and sanitize response
                    const validatedResults = [];
                    for (let i = 0; i < Math.min(response.length, config.max_results); i++) {
                        const item = response[i];
                        if (item && typeof item === 'object') {
                            validatedResults.push(item);
                        }
                    }
                    
                    // Cache results
                    const cacheKey = 'search_' + query;
                    cache.set(cacheKey, validatedResults);
                    
                    displayResults(validatedResults);
                },
                error: function(xhr, status, error) {
                    if (status !== 'abort') {
                        log('AJAX fallback error');
                        showError('Search temporarily unavailable');
                    }
                },
                complete: function() {
                    currentRequest = null;
                }
            });
        }
        
        function showError(message) {
            const errorDiv = createElement('div', 'no-results');
            errorDiv.setAttribute('role', 'alert');
            errorDiv.textContent = message || 'An error occurred';
            
            $resultsBox.empty().append(errorDiv).addClass('active');
        }
        
        function displayResults(results) {
            log('Displaying results:', results ? results.length : 0);
            
            if (!results || !Array.isArray(results) || results.length === 0) {
                showError('No products found');
                return;
            }
            
            // Create results list using safe DOM methods
            const ul = createElement('ul');
            ul.setAttribute('role', 'list');
            
            for (let i = 0; i < results.length; i++) {
                const item = results[i];
                
                if (!item || typeof item !== 'object') {
                    continue;
                }
                
                const li = createElement('li');
                li.setAttribute('role', 'option');
                
                if (item.type === 'product' && item.in_stock === false) {
                    li.className = 'out-of-stock';
                }
                
                const link = createElement('a');
                link.href = escapeHtml(item.url || '#');
                
                // Add image if enabled
                if (config.show_image && item.image) {
                    const img = createElement('img');
                    img.className = 'product-image';
                    img.src = escapeHtml(item.image);
                    img.alt = '';
                    img.loading = 'lazy';
                    img.onerror = function() {
                        this.style.display = 'none';
                    };
                    link.appendChild(img);
                }
                
                // Add product details
                const details = createElement('div', 'product-details');
                
                // Title (always escaped)
                const title = createElement('span', 'product-title');
                title.textContent = item.title || 'Untitled';
                details.appendChild(title);
                
                // Type-specific content
                if (item.type === 'product') {
                    // Price (HTML allowed but sanitized)
                    if (config.show_price && item.price_html) {
                        const priceSpan = createElement('span', 'product-price');
                        // For price HTML, we need to be extra careful
                        const priceText = item.price_html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
                        priceSpan.innerHTML = priceText; // Price HTML may contain formatting
                        details.appendChild(priceSpan);
                    } else if (config.show_price && item.price) {
                        const priceSpan = createElement('span', 'product-price');
                        priceSpan.textContent = item.price;
                        details.appendChild(priceSpan);
                    }
                    
                    // SKU
                    if (config.show_sku && item.sku) {
                        const skuSpan = createElement('span', 'product-sku');
                        skuSpan.textContent = 'SKU: ' + item.sku;
                        details.appendChild(skuSpan);
                    }
                    
                    // Stock status
                    if (item.in_stock === false) {
                        const stockSpan = createElement('span', 'product-stock');
                        stockSpan.textContent = 'Out of Stock';
                        details.appendChild(stockSpan);
                    }
                } else {
                    // Non-product items
                    if (item.type_label) {
                        const typeSpan = createElement('span', 'result-type');
                        typeSpan.textContent = item.type_label;
                        details.appendChild(typeSpan);
                    }
                    
                    if (item.excerpt) {
                        const excerptSpan = createElement('span', 'result-excerpt');
                        excerptSpan.textContent = item.excerpt;
                        details.appendChild(excerptSpan);
                    }
                }
                
                link.appendChild(details);
                li.appendChild(link);
                ul.appendChild(li);
            }
            
            // Add view all link
            const searchUrl = escapeHtml(($form.attr('action') || '/') + '?s=' + encodeURIComponent(lastQuery) + '&post_type=product');
            const viewAllLink = createElement('a', 'view-all');
            viewAllLink.href = searchUrl;
            viewAllLink.textContent = 'View all results →';
            
            // Clear and append results
            $resultsBox.empty().append(ul).append(viewAllLink).addClass('active');
        }
    }
    
})(jQuery);