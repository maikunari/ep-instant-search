(function($) {
    'use strict';
    
    // Bail early if config not available
    if (typeof ep_instant_search === 'undefined') {
        return;
    }
    
    // Debug logging helper
    function log() {
        if (ep_instant_search.debug && console && console.log) {
            console.log.apply(console, ['[EP Instant Search]'].concat(Array.prototype.slice.call(arguments)));
        }
    }
    
    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
    }
    
    // Cache DOM queries
    var cache = {};
    var activeRequests = {};
    var abortController = null;
    
    // Debounce function for search
    function debounce(func, wait) {
        var timeout;
        return function executedFunction() {
            var context = this;
            var args = arguments;
            var later = function() {
                timeout = null;
                func.apply(context, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // Initialize on DOM ready
    $(document).ready(function() {
        log('Initializing...');
        
        var selectors = ep_instant_search.selectors || '.search-field, input[name="s"]';
        var $searchInputs = $(selectors);
        
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
    });
    
    function initSearchInput($input) {
        // Skip if already initialized
        if ($input.data('ep-instant-search-init')) {
            return;
        }
        
        $input.data('ep-instant-search-init', true);
        
        var $form = $input.closest('form');
        var $parent = $form.length ? $form : $input.parent();
        var $resultsBox = $('<div class="ep-instant-results" aria-live="polite" role="listbox"></div>');
        var lastQuery = '';
        var currentRequest;
        
        // Setup positioning
        $parent.css('position', 'relative');
        $parent.append($resultsBox);
        
        // Bind events
        $input
            .attr('autocomplete', 'off')
            .attr('aria-autocomplete', 'list')
            .attr('aria-controls', 'ep-results-' + $input.index())
            .on('input.ep_instant_search', handleInput)
            .on('keydown.ep_instant_search', handleKeydown)
            .on('focus.ep_instant_search', handleFocus)
            .on('blur.ep_instant_search', handleBlur);
        
        $resultsBox.attr('id', 'ep-results-' + $input.index());
        
        // Create debounced search function
        var debouncedSearch = debounce(function(query) {
            performSearch(query);
        }, ep_instant_search.search_delay || 300);
        
        function handleInput(e) {
            var query = $input.val().trim();
            
            // Cancel any pending search
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            
            // Abort previous AJAX request if using fallback
            if (currentRequest && currentRequest.abort) {
                currentRequest.abort();
            }
            
            // Clear if too short
            if (query.length < ep_instant_search.min_chars) {
                $resultsBox.removeClass('active').empty();
                lastQuery = '';
                return;
            }
            
            // Skip if same
            if (query === lastQuery) {
                return;
            }
            
            lastQuery = query;
            
            // Show loading spinner immediately for instant feedback
            // But only if we're not already showing it (to avoid restart)
            if (!$resultsBox.find('.loading').length) {
                $resultsBox
                    .html('<div class="loading" role="status"><div class="spinner"></div><span>Loading results...</span></div>')
                    .addClass('active');
            }
            
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
                        var $selected = $resultsBox.find('.selected a');
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
            var $items = $resultsBox.find('li');
            var $current = $items.filter('.selected');
            var index = $items.index($current);
            
            $items.removeClass('selected');
            
            if (direction === 'down') {
                index = index < $items.length - 1 ? index + 1 : 0;
            } else {
                index = index > 0 ? index - 1 : $items.length - 1;
            }
            
            $items.eq(index).addClass('selected');
        }
        
        function performSearch(query) {
            log('Searching for:', query);
            
            // Check cache first
            var cacheKey = 'search_' + query;
            if (cache[cacheKey]) {
                log('Using cached results');
                displayResults(cache[cacheKey]);
                return;
            }
            
            // Cancel previous request if still pending
            if (abortController) {
                abortController.abort();
            }
            
            // Create new AbortController for this request
            abortController = new AbortController();
            
            // Prepare REST API request data
            var requestData = {
                s: query,
                post_type: 'product',
                per_page: ep_instant_search.max_results || 20,
                search_fields: [
                    'post_title',
                    'post_content', 
                    'meta._sku',
                    'meta._variations_skus'
                ]
            };
            
            // Build REST API URL
            var restUrl = (window.location.origin || '') + '/wp-json/elasticpress/v1/search';
            
            log('Making REST API request to:', restUrl);
            log('Request data:', requestData);
            
            // Use fetch for REST API call
            fetch(restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ep_instant_search.nonce || '',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(requestData),
                signal: abortController.signal,
                credentials: 'same-origin'
            })
            .then(function(response) {
                // Check if response is ok (status in the range 200-299)
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status + ' ' + response.statusText);
                }
                return response.json();
            })
            .then(function(data) {
                log('REST API response received:', data);
                
                // Handle different response structures
                var results = [];
                
                // Check if data has a specific structure
                if (data && Array.isArray(data)) {
                    results = data;
                } else if (data && data.data && Array.isArray(data.data)) {
                    results = data.data;
                } else if (data && data.posts && Array.isArray(data.posts)) {
                    results = data.posts;
                } else {
                    console.error('Unexpected REST API response structure:', data);
                    $resultsBox.html('<div class="no-results">Unexpected response format.</div>').addClass('active');
                    return;
                }
                
                // Transform REST API response to match expected format
                var transformedResults = results.map(function(item) {
                    return {
                        id: item.id || item.ID,
                        title: item.title?.rendered || item.post_title || item.title || '',
                        url: item.link || item.permalink || '#',
                        type: item.type || item.post_type || 'product',
                        price: item.meta?.price || item.price || '',
                        price_html: item.meta?.price_html || item.price_html || '',
                        image: item.featured_media_url || item.thumbnail || item.image || '',
                        sku: item.meta?._sku || item.sku || '',
                        in_stock: item.meta?.in_stock !== false,
                        excerpt: item.excerpt?.rendered || item.excerpt || ''
                    };
                });
                
                // Cache for 1 minute
                cache[cacheKey] = transformedResults;
                setTimeout(function() {
                    delete cache[cacheKey];
                }, 60000);
                
                displayResults(transformedResults);
                abortController = null;
            })
            .catch(function(error) {
                // Don't show error for aborted requests
                if (error.name === 'AbortError') {
                    log('Request aborted');
                    return;
                }
                
                log('REST API error:', error);
                console.error('REST API Error Details:', error);
                
                // Fallback to AJAX if REST fails
                log('Falling back to AJAX endpoint');
                performSearchAjaxFallback(query);
            });
        }
        
        // Fallback function using original AJAX
        function performSearchAjaxFallback(query) {
            var requestData = {
                action: 'ep_instant_search',
                q: query
            };
            
            currentRequest = $.ajax({
                url: ep_instant_search.ajax_url,
                type: 'GET',
                dataType: 'json',
                data: requestData,
                success: function(response) {
                    log('AJAX fallback response received:', response);
                    var results = response;
                    
                    // Cache for 1 minute
                    var cacheKey = 'search_' + query;
                    cache[cacheKey] = results;
                    setTimeout(function() {
                        delete cache[cacheKey];
                    }, 60000);
                    
                    displayResults(results);
                },
                error: function(xhr, status, error) {
                    if (status !== 'abort') {
                        log('AJAX fallback error:', status, error);
                        $resultsBox.html('<div class="no-results">Search error. Please try again.</div>').addClass('active');
                    }
                },
                complete: function() {
                    currentRequest = null;
                }
            });
        }
        
        function displayResults(results) {
            log('Displaying results:', results);
            
            if (!results || results.length === 0) {
                $resultsBox.html('<div class="no-results" role="status">No products found</div>').addClass('active');
                return;
            }
            
            var html = '<ul role="list">';
            
            $.each(results, function(i, item) {
                var itemClass = '';
                if (item.type === 'product' && item.in_stock === false) {
                    itemClass = ' out-of-stock';
                }
                
                html += '<li role="option" class="' + itemClass + '">';
                html += '<a href="' + escapeHtml(item.url) + '">';
                
                if (ep_instant_search.show_image === 'yes' && item.image) {
                    html += '<img src="' + escapeHtml(item.image) + '" alt="" class="product-image" loading="lazy" />';
                }
                
                html += '<div class="product-details">';
                html += '<span class="product-title">' + escapeHtml(item.title) + '</span>';
                
                // Show different info based on post type
                if (item.type === 'product') {
                    // Product-specific display
                    if (ep_instant_search.show_price === 'yes' && item.price) {
                        html += '<span class="product-price">' + item.price + '</span>';
                    }
                    
                    if (ep_instant_search.show_sku === 'yes' && item.sku) {
                        html += '<span class="product-sku">SKU: ' + escapeHtml(item.sku) + '</span>';
                    }
                    
                    if (item.in_stock === false) {
                        html += '<span class="product-stock">Out of Stock</span>';
                    }
                } else {
                    // Non-product display (posts, pages, etc.)
                    if (item.type_label) {
                        html += '<span class="result-type">' + escapeHtml(item.type_label) + '</span>';
                    }
                    
                    if (item.excerpt) {
                        html += '<span class="result-excerpt">' + escapeHtml(item.excerpt) + '</span>';
                    }
                }
                
                html += '</div>';
                html += '</a>';
                html += '</li>';
            });
            
            html += '</ul>';
            
            // Add view all link
            var searchUrl = ($form.attr('action') || '/') + '?s=' + encodeURIComponent(lastQuery) + '&post_type=product';
            html += '<a href="' + escapeHtml(searchUrl) + '" class="view-all">View all results →</a>';
            
            $resultsBox.html(html).addClass('active');
        }
    }
    
})(jQuery);