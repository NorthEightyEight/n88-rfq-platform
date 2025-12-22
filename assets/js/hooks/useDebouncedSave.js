/**
 * useDebouncedSave Hook
 * 
 * Milestone 1.3.5: Debounced Save + Failure Recovery
 * 
 * Implements 500ms trailing-edge debounce for board layout saves.
 * Handles client-side revision tracking for last-write-wins concurrency.
 * Manages unsynced state and failure recovery.
 */

(function() {
    'use strict';

    // Hard fail if React is not available
    if (typeof window === 'undefined' || !window.React || !window.React.useRef || !window.React.useCallback || !window.React.useEffect) {
        throw new Error('useDebouncedSave: React is required. Please load React before this script.');
    }

    const React = window.React;

    /**
     * useDebouncedSave - Custom hook for debounced board layout saves
     * 
     * @param {number} boardId - Board ID to save
     * @param {Function} getItems - Function that returns current items array from store
     * @returns {Object} { triggerSave, unsynced, clearUnsynced }
     */
    function useDebouncedSave(boardId, getItems) {
        // Client-side revision counter (monotonically increasing)
        const clientRevisionRef = React.useRef(0);
        
        // Debounce timer ref
        const debounceTimerRef = React.useRef(null);
        
        // Unsynced state
        const [unsynced, setUnsynced] = React.useState(false);
        
        // Pending save ref (to track if we're waiting for a response)
        const pendingSaveRef = React.useRef(null);

        /**
         * Save function - sends full snapshot to server
         */
        const performSave = React.useCallback(function(revision) {
            if (!boardId || boardId === 0) {
                console.warn('useDebouncedSave: boardId is required');
                return;
            }

            const items = getItems();
            if (!Array.isArray(items)) {
                console.warn('useDebouncedSave: getItems() must return an array');
                return;
            }

            // Prepare full snapshot payload
            const payload = {
                board_id: boardId,
                items: items,
                client_revision: revision,
            };

            // Store pending save info (for reference, but we'll use the revision parameter directly)
            pendingSaveRef.current = {
                revision: revision,
                timestamp: Date.now(),
            };

            // Get AJAX URL and nonce from WordPress
            const ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';
            const nonce = window.n88BoardNonce || '';

            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'n88_save_board_layout');
            formData.append('board_id', boardId);
            formData.append('items', JSON.stringify(items));
            formData.append('client_revision', revision);
            if (nonce) {
                formData.append('nonce', nonce);
            }

            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                // Check if this response matches the latest client revision
                // Compare the revision that was sent with the current revision
                // If not, ignore it (stale response)
                if (revision !== clientRevisionRef.current) {
                    console.log('useDebouncedSave: Ignoring stale response (revision ' + revision + ' vs current ' + clientRevisionRef.current + ')');
                    return;
                }

                // Clear pending save
                pendingSaveRef.current = null;

                if (data && data.success) {
                    // Success - clear unsynced state
                    setUnsynced(false);
                } else {
                    // Failure - mark as unsynced
                    setUnsynced(true);
                    console.error('useDebouncedSave: Save failed', data);
                }
            })
            .catch(function(error) {
                // Network error or other failure
                // Check if this is still the latest revision (compare sent revision with current)
                if (revision !== clientRevisionRef.current) {
                    console.log('useDebouncedSave: Ignoring stale error (revision ' + revision + ' vs current ' + clientRevisionRef.current + ')');
                    return;
                }

                // Clear pending save
                pendingSaveRef.current = null;

                // Mark as unsynced
                setUnsynced(true);
                console.error('useDebouncedSave: Save error', error);
            });
        }, [boardId, getItems]);

        /**
         * Trigger save - debounced with 500ms trailing edge
         */
        const triggerSave = React.useCallback(function() {
            // Clear existing timer
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
                debounceTimerRef.current = null;
            }

            // Increment client revision
            clientRevisionRef.current += 1;
            const currentRevision = clientRevisionRef.current;

            // Set new debounce timer (trailing edge - fires after 500ms of inactivity)
            debounceTimerRef.current = setTimeout(function() {
                debounceTimerRef.current = null;
                performSave(currentRevision);
            }, 500);
        }, [performSave]);

        /**
         * Clear unsynced state (manual clear)
         */
        const clearUnsynced = React.useCallback(function() {
            setUnsynced(false);
        }, []);

        // Cleanup on unmount
        React.useEffect(function() {
            return function() {
                if (debounceTimerRef.current) {
                    clearTimeout(debounceTimerRef.current);
                }
            };
        }, []);

        return {
            triggerSave: triggerSave,
            unsynced: unsynced,
            clearUnsynced: clearUnsynced,
        };
    }

    // Export to global namespace for WordPress UMD pattern
    if (typeof window.N88StudioOS === 'undefined') {
        window.N88StudioOS = {};
    }
    window.N88StudioOS.useDebouncedSave = useDebouncedSave;
})();

