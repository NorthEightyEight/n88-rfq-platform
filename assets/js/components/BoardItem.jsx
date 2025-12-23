/**
 * BoardItem Component
 * 
 * Milestone 1.3.4: Canvas Shell + BoardItem (Drag + Z + Morph)
 * 
 * Draggable board item with z-index stacking and displayMode morphing.
 */

import React from 'react';
import { motion, AnimatePresence, useMotionValue } from 'framer-motion';

// Access Zustand store from global namespace (WordPress UMD pattern)
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found. Ensure useBoardStore.js is loaded before this component.');
});

/**
 * BoardItem - Draggable board item with morphing display modes
 * 
 * @param {Object} props
 * @param {Object} props.item - Board item from store {id, x, y, z, width, height, displayMode}
 * @param {Function} props.onLayoutChanged - Callback when layout changes
 */
const BoardItem = ({ item, onLayoutChanged }) => {
    const bringToFront = useBoardStore((state) => state.bringToFront);
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Motion values for drag position
    const x = useMotionValue(item.x);
    const y = useMotionValue(item.y);
    
    // Local state for resize (live preview during resize)
    const [resizeState, setResizeState] = React.useState({
        isResizing: false,
        startX: 0,
        startY: 0,
        startWidth: 0,
        startHeight: 0,
    });
    
    // Safety: Force end resize if stuck (fallback timeout)
    const resizeTimeoutRef = React.useRef(null);
    
    // Minimum dimensions (enforced during resize)
    const MIN_WIDTH = 100;
    const MIN_HEIGHT = 100;
    
    // Maximum dimensions (80% of viewport)
    const MAX_WIDTH = Math.floor(0.8 * (typeof window !== 'undefined' ? window.innerWidth : 1920));
    const MAX_HEIGHT = Math.floor(0.8 * (typeof window !== 'undefined' ? window.innerHeight : 1080));

    // Update motion values when item position changes from store
    React.useEffect(() => {
        x.set(item.x);
        y.set(item.y);
    }, [item.x, item.y, x, y]);
    
    // Force end resize function (reusable for all recovery scenarios)
    const forceEndResize = React.useCallback(() => {
        // Clear timeout
        if (resizeTimeoutRef.current) {
            clearTimeout(resizeTimeoutRef.current);
            resizeTimeoutRef.current = null;
        }
        
        // Force reset cursor
        if (document.body) {
            document.body.style.cursor = '';
            document.body.style.pointerEvents = '';
        }
        
        // Get final dimensions before clearing
        setResizeState(prev => {
            if (!prev.isResizing) return prev;
            
            const finalWidth = prev.currentWidth || prev.startWidth || item.width;
            const finalHeight = prev.currentHeight || prev.startHeight || item.height;
            
            // Update store if we have valid dimensions
            if (finalWidth && finalHeight && finalWidth > 0 && finalHeight > 0) {
                try {
                    updateLayout(item.id, {
                        width: Math.max(MIN_WIDTH, Math.min(finalWidth, MAX_WIDTH)),
                        height: Math.max(MIN_HEIGHT, Math.min(finalHeight, MAX_HEIGHT)),
                    });
                } catch (error) {
                    console.error('BoardItem: Error in forceEndResize', error);
                }
            }
            
            return {
                isResizing: false,
                startX: 0,
                startY: 0,
                startWidth: 0,
                startHeight: 0,
                currentWidth: undefined,
                currentHeight: undefined,
            };
        });
    }, [item.id, item.width, item.height, updateLayout, MIN_WIDTH, MAX_WIDTH, MIN_HEIGHT, MAX_HEIGHT]);
    
    // Safety: Force end resize on Escape key (user can manually escape stuck state)
    React.useEffect(() => {
        const handleEscape = (e) => {
            if (e.key === 'Escape' && resizeState.isResizing) {
                console.warn('BoardItem: Escape key pressed - forcing end resize');
                forceEndResize();
            }
        };
        
        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [resizeState.isResizing, forceEndResize]);
    
    // Global stuck-state detection and recovery (monitor for stuck resize)
    // More aggressive monitoring for high-level testing
    React.useEffect(() => {
        if (!resizeState.isResizing) return;
        
        // Track last activity time
        let lastActivityTime = Date.now();
        let checkInterval = null;
        
        // Aggressive stuck-state check: every 1 second, check if still resizing
        checkInterval = setInterval(() => {
            // Check if still resizing
            setResizeState(prev => {
                if (!prev.isResizing) return prev;
                
                const timeSinceActivity = Date.now() - lastActivityTime;
                
                // If no activity for 2 seconds, force end (more aggressive for testing)
                if (timeSinceActivity > 2000) {
                    console.warn('BoardItem: Detected stuck resize state (no activity for 2s) - forcing recovery');
                    // Force end immediately
                    setTimeout(() => {
                        forceEndResize();
                    }, 0);
                    return {
                        ...prev,
                        isResizing: false,
                        startX: 0,
                        startY: 0,
                        startWidth: 0,
                        startHeight: 0,
                        currentWidth: undefined,
                        currentHeight: undefined,
                    };
                }
                
                return prev;
            });
        }, 1000); // Check every 1 second
        
        // Activity monitor: update lastActivityTime on any mouse movement
        const activityMonitor = () => {
            lastActivityTime = Date.now();
        };
        
        document.addEventListener('mousemove', activityMonitor, { passive: true });
        document.addEventListener('mouseup', activityMonitor, { passive: true });
        document.addEventListener('pointermove', activityMonitor, { passive: true });
        document.addEventListener('pointerup', activityMonitor, { passive: true });
        
        return () => {
            if (checkInterval) {
                clearInterval(checkInterval);
            }
            document.removeEventListener('mousemove', activityMonitor);
            document.removeEventListener('mouseup', activityMonitor);
            document.removeEventListener('pointermove', activityMonitor);
            document.removeEventListener('pointerup', activityMonitor);
        };
    }, [resizeState.isResizing, forceEndResize]);
    
    // Current dimensions (use resize preview if resizing, otherwise use store)
    // If resize just ended, use the final dimensions from resize state until store updates
    const currentWidth = resizeState.isResizing 
        ? resizeState.currentWidth 
        : (resizeState.currentWidth !== undefined ? resizeState.currentWidth : item.width);
    const currentHeight = resizeState.isResizing 
        ? resizeState.currentHeight 
        : (resizeState.currentHeight !== undefined ? resizeState.currentHeight : item.height);
    
    // Clear temporary dimensions once store has updated (check if item.width matches)
    React.useEffect(() => {
        if (!resizeState.isResizing && resizeState.currentWidth !== undefined) {
            // Check if store has updated to match our final dimensions
            const widthMatch = Math.abs(resizeState.currentWidth - item.width) < 1;
            const heightMatch = Math.abs(resizeState.currentHeight - item.height) < 1;
            
            if (widthMatch && heightMatch) {
                // Store has updated, clear temporary dimensions
                setResizeState(prev => ({
                    ...prev,
                    currentWidth: undefined,
                    currentHeight: undefined,
                }));
            }
        }
    }, [item.width, item.height, resizeState.isResizing, resizeState.currentWidth, resizeState.currentHeight]);

    // CRITICAL: Handle clicks during resize/drag to prevent stuck states
    const handleClick = React.useCallback((e) => {
        // If resize is active, force end it on click
        if (resizeRef.current.isActive || resizeState.isResizing) {
            console.warn('[BoardItem] Click detected during resize - forcing end for item:', item.id);
            e.stopPropagation();
            e.preventDefault();
            
            // Force end resize immediately
            resizeRef.current.isActive = false;
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            
            // Get final dimensions before clearing
            const finalWidth = resizeState.currentWidth || resizeState.startWidth || item.width;
            const finalHeight = resizeState.currentHeight || resizeState.startHeight || item.height;
            
            // Update store with final dimensions
            if (finalWidth && finalHeight) {
                try {
                    updateLayout(item.id, {
                        width: Math.max(MIN_WIDTH, Math.min(finalWidth, MAX_WIDTH)),
                        height: Math.max(MIN_HEIGHT, Math.min(finalHeight, MAX_HEIGHT)),
                    });
                } catch (error) {
                    console.error('BoardItem: Error updating layout on click', error);
                }
            }
            
            // Clear resize state
            setResizeState({
                isResizing: false,
                startX: 0,
                startY: 0,
                startWidth: 0,
                startHeight: 0,
                currentWidth: undefined,
                currentHeight: undefined,
            });
            
            // Reset cursor
            if (document.body) {
                document.body.style.cursor = '';
                document.body.style.pointerEvents = '';
            }
            
            // Remove from global tracker
            if (window.N88StudioOS && window.N88StudioOS.activeResizes) {
                window.N88StudioOS.activeResizes.delete(item.id);
            }
            
            // Trigger layout changed callback
            if (onLayoutChanged && finalWidth && finalHeight) {
                setTimeout(() => {
                    onLayoutChanged({
                        id: item.id,
                        x: item.x,
                        y: item.y,
                        width: finalWidth,
                        height: finalHeight,
                        displayMode: item.displayMode,
                    });
                }, 0);
            }
        }
    }, [resizeState, item.id, item.x, item.y, item.width, item.height, item.displayMode, updateLayout, onLayoutChanged, MIN_WIDTH, MIN_HEIGHT, MAX_WIDTH, MAX_HEIGHT]);

    const handleDragStart = () => {
        // CRITICAL: Force end any stuck resize before drag starts
        if (resizeRef.current.isActive || resizeState.isResizing) {
            console.warn('[BoardItem] DragStart: Resize still active, forcing end for item:', item.id);
            resizeRef.current.isActive = false;
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            setResizeState(prev => {
                if (!prev.isResizing) return prev;
                return {
                    ...prev,
                    isResizing: false,
                    currentWidth: undefined,
                    currentHeight: undefined,
                };
            });
            // Reset cursor
            if (document.body) {
                document.body.style.cursor = '';
            }
        }
        
        // Bring item to front on drag start
        bringToFront(item.id);
    };

    const handleDragEnd = (event, info) => {
        // Get final position from motion values (they track the drag)
        const newX = x.get();
        const newY = y.get();

        // Update local state optimistically
        updateLayout(item.id, {
            x: newX,
            y: newY,
        });

        // Emit layoutChanged callback to parent (triggers debounced save)
        if (onLayoutChanged) {
            onLayoutChanged({
                id: item.id,
                x: newX,
                y: newY,
                width: currentWidth,
                height: currentHeight,
                displayMode: item.displayMode,
            });
        }
    };
    
    // Resize start handler
    const handleResizeStart = (e) => {
        e.stopPropagation(); // Prevent drag from starting
        e.preventDefault();
        
        // CRITICAL: Force end any existing resize first (prevent stuck state on multiple resizes)
        // Check both state AND ref (double safety)
        if (resizeState.isResizing || resizeRef.current.isActive) {
            console.warn('[BoardItem] Previous resize still active - forcing end before new resize, item:', item.id);
            // Force end using ref (immediate)
            resizeRef.current.isActive = false;
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            // Also use forceEndResize for proper cleanup
            forceEndResize();
            // Wait longer to ensure complete cleanup
            setTimeout(() => {
                // Double check before starting new resize
                if (!resizeRef.current.isActive) {
                    startNewResize(e);
                } else {
                    console.warn('[BoardItem] Still active after cleanup, retrying...');
                    setTimeout(() => startNewResize(e), 100);
                }
            }, 150);
            return;
        }
        
        startNewResize(e);
    };
    
    // Helper function to start a new resize
    const startNewResize = (e) => {
        // Safety: Clear any existing timeout
        if (resizeTimeoutRef.current) {
            clearTimeout(resizeTimeoutRef.current);
            resizeTimeoutRef.current = null;
        }
        
        // Bring item to front
        bringToFront(item.id);
        
        // Get initial mouse position and item dimensions
        const startX = e.clientX;
        const startY = e.clientY;
        
        setResizeState({
            isResizing: true,
            startX: startX,
            startY: startY,
            startWidth: item.width,
            startHeight: item.height,
            currentWidth: item.width,
            currentHeight: item.height,
        });
        
        // Safety: Force end resize after 30 seconds (fallback if mouseup/blur don't fire)
        resizeTimeoutRef.current = setTimeout(() => {
            console.warn('BoardItem: Resize timeout - forcing end resize');
            setResizeState(prev => {
                if (!prev.isResizing) return prev;
                return {
                    isResizing: false,
                    startX: 0,
                    startY: 0,
                    startWidth: 0,
                    startHeight: 0,
                    currentWidth: undefined,
                    currentHeight: undefined,
                };
            });
            resizeTimeoutRef.current = null;
        }, 30000); // 30 second safety timeout
    };
    
    // NEW APPROACH: Ref-based resize handler (easier to debug, more reliable)
    // Use refs to track state outside React lifecycle to avoid closure issues
    const resizeRef = React.useRef({
        isActive: false,
        startX: 0,
        startY: 0,
        startWidth: 0,
        startHeight: 0,
        rafId: null,
        listenersAttached: false,
        visibilityHandler: null,
        lastActivityTime: 0, // Track last mouse activity
        lastEvent: null, // Store latest mouse event for RAF
    });
    
    // CRITICAL: Create stable mouse move handler outside effect to prevent listener accumulation
    // This handler is created once and reused, preventing memory leaks
    const handleMouseMoveStable = React.useCallback((e) => {
        // Check ref first (faster than state check)
        if (!resizeRef.current.isActive) {
            return; // Silent return - no console spam
        }
        
        // Update activity time (for stuck detection)
        resizeRef.current.lastActivityTime = Date.now();
        
        // Store latest event for RAF callback
        resizeRef.current.lastEvent = e;
        
        // CRITICAL: Throttle RAF - only schedule if not already scheduled
        // This prevents excessive RAF calls that cause page freeze
        if (resizeRef.current.rafId === null) {
            resizeRef.current.rafId = requestAnimationFrame(() => {
                // Clear RAF ID immediately to allow next frame
                resizeRef.current.rafId = null;
                
                // Double-check still active
                if (!resizeRef.current.isActive) return;
                
                // Get latest mouse position from stored event
                if (!resizeRef.current.lastEvent) return;
                const latestEvent = resizeRef.current.lastEvent;
                
                // Calculate dimensions from ref values (always fresh)
                const deltaX = latestEvent.clientX - resizeRef.current.startX;
                const deltaY = latestEvent.clientY - resizeRef.current.startY;
                
                let newWidth = resizeRef.current.startWidth + deltaX;
                let newHeight = resizeRef.current.startHeight + deltaY;
                
                // Clamp to MIN/MAX
                newWidth = Math.max(MIN_WIDTH, Math.min(newWidth, MAX_WIDTH));
                newHeight = Math.max(MIN_HEIGHT, Math.min(newHeight, MAX_HEIGHT));
                
                // Update state (only if changed to prevent unnecessary re-renders)
                setResizeState(prev => {
                    if (!prev.isResizing) return prev;
                    // Only update if dimensions actually changed (prevents render loops)
                    if (prev.currentWidth === newWidth && prev.currentHeight === newHeight) {
                        return prev;
                    }
                    return {
                        ...prev,
                        currentWidth: newWidth,
                        currentHeight: newHeight,
                    };
                });
            });
        }
    }, [MIN_WIDTH, MIN_HEIGHT, MAX_WIDTH, MAX_HEIGHT]);
    
    // Global resize state tracker (shared across all items)
    // This helps detect stuck states that persist across items
    if (typeof window.N88StudioOS === 'undefined') {
        window.N88StudioOS = {};
    }
    if (!window.N88StudioOS.activeResizes) {
        window.N88StudioOS.activeResizes = new Set();
    }
    
    // Global cleanup on unmount - CRITICAL for preventing stuck states
    React.useEffect(() => {
        return () => {
            // Force cleanup on unmount
            console.log('[BoardItem] Component unmounting, forcing cleanup for item:', item.id);
            resizeRef.current.isActive = false;
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            if (document.body) {
                document.body.style.cursor = '';
                document.body.style.pointerEvents = '';
            }
        };
    }, [item.id]);
    
    // Resize move handler - NEW SIMPLIFIED APPROACH
    React.useEffect(() => {
        if (!resizeState.isResizing) {
            // Reset ref when not resizing (CRITICAL cleanup)
            if (resizeRef.current.isActive) {
                console.log('[BoardItem] Resize state false, cleaning up ref for item:', item.id);
            }
            resizeRef.current.isActive = false;
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            resizeRef.current.listenersAttached = false;
            return;
        }
        
        // Mark as active ONLY if not already active (prevent duplicates)
        if (resizeRef.current.isActive) {
            console.warn('[BoardItem] Resize already active, skipping setup for item:', item.id);
            return;
        }
        
        // Mark as active
        resizeRef.current.isActive = true;
        resizeRef.current.startX = resizeState.startX;
        resizeRef.current.startY = resizeState.startY;
        resizeRef.current.startWidth = resizeState.startWidth;
        resizeRef.current.startHeight = resizeState.startHeight;
        resizeRef.current.lastActivityTime = Date.now();
        
        // Add to global tracker
        if (window.N88StudioOS && window.N88StudioOS.activeResizes) {
            window.N88StudioOS.activeResizes.add(item.id);
        }
        
        console.log('[BoardItem] Resize started for item:', item.id);
        
        // Use the stable handler created outside the effect
        const handleMouseMove = handleMouseMoveStable;
        
        // NEW APPROACH: Simpler end handler with ref-based checks
        const handleResizeEnd = (e) => {
            // Check ref first (prevents multiple calls) - CRITICAL
            if (!resizeRef.current.isActive) {
                console.log('[BoardItem] ResizeEnd: already ended, ignoring');
                return;
            }
            
            console.log('[BoardItem] ResizeEnd called for item:', item.id, 'Event:', e ? e.type : 'manual');
            
            // Mark as inactive IMMEDIATELY (before anything else) - CRITICAL
            // Do this FIRST before any other operations
            const wasActive = resizeRef.current.isActive;
            resizeRef.current.isActive = false;
            
            // Remove from global tracker IMMEDIATELY
            if (window.N88StudioOS && window.N88StudioOS.activeResizes) {
                window.N88StudioOS.activeResizes.delete(item.id);
            }
            
            // CRITICAL: Force update state IMMEDIATELY to enable drag
            // Don't wait for the rest of the cleanup
            setResizeState(prev => {
                if (!prev.isResizing && !wasActive) return prev;
                return {
                    ...prev,
                    isResizing: false, // Enable drag immediately
                };
            });
            
            // Cancel RAF immediately
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            
            // Clear timeout
            if (resizeTimeoutRef.current) {
                clearTimeout(resizeTimeoutRef.current);
                resizeTimeoutRef.current = null;
            }
            
            // Remove listeners (simpler - just the ones we added)
            try {
                document.removeEventListener('mousemove', handleMouseMove);
                document.removeEventListener('mouseup', handleResizeEnd);
                window.removeEventListener('mouseup', handleResizeEnd);
                window.removeEventListener('blur', handleResizeEnd);
                window.removeEventListener('mouseleave', handleResizeEnd);
                document.removeEventListener('pointerup', handleResizeEnd);
                document.removeEventListener('pointerleave', handleResizeEnd);
                if (resizeRef.current.visibilityHandler) {
                    document.removeEventListener('visibilitychange', resizeRef.current.visibilityHandler);
                }
                
                // Second pass: remove with capture/non-capture (aggressive cleanup)
                document.removeEventListener('mousemove', handleMouseMove, true);
                document.removeEventListener('mousemove', handleMouseMove, false);
                document.removeEventListener('mouseup', handleResizeEnd, true);
                document.removeEventListener('mouseup', handleResizeEnd, false);
                window.removeEventListener('mouseup', handleResizeEnd, true);
                window.removeEventListener('mouseup', handleResizeEnd, false);
                
                resizeRef.current.listenersAttached = false;
                resizeRef.current.visibilityHandler = null;
                console.log('[BoardItem] Listeners removed (aggressive cleanup)');
            } catch (err) {
                console.warn('[BoardItem] Error removing listeners:', err);
            }
            
            // Reset cursor IMMEDIATELY (before state update) - CRITICAL for drag to work
            try {
                if (document.body) {
                    document.body.style.cursor = '';
                    document.body.style.pointerEvents = '';
                }
                // Also reset on document element
                if (document.documentElement) {
                    document.documentElement.style.cursor = '';
                }
            } catch (err) {
                console.warn('[BoardItem] Error resetting cursor:', err);
            }
            
            // Get final dimensions from state BEFORE clearing state
            let finalWidth, finalHeight;
            setResizeState(prev => {
                // Safety check: only process if still resizing
                // NOTE: Don't check resizeRef.current.isActive here - we already set it to false above
                if (!prev.isResizing) {
                    console.log('[BoardItem] ResizeEnd: state already cleared, item:', item.id);
                    return prev;
                }
                
                // Clamp final dimensions to MIN/MAX (safety check)
                finalWidth = prev.currentWidth || prev.startWidth || item.width;
                finalHeight = prev.currentHeight || prev.startHeight || item.height;
                finalWidth = Math.max(MIN_WIDTH, Math.min(finalWidth, MAX_WIDTH));
                finalHeight = Math.max(MIN_HEIGHT, Math.min(finalHeight, MAX_HEIGHT));
                
                // Update store FIRST (synchronously) before clearing state
                try {
                    updateLayout(item.id, {
                        width: finalWidth,
                        height: finalHeight,
                    });
                } catch (error) {
                    console.error('BoardItem: Error updating layout', error);
                }
                
                // Clear resize state AFTER store update, but keep final dimensions temporarily
                // This prevents flicker when component re-renders before store update propagates
                // CRITICAL: Set isResizing to false IMMEDIATELY so drag can work
                return {
                    isResizing: false, // CRITICAL: This enables drag again
                    startX: 0,
                    startY: 0,
                    startWidth: 0,
                    startHeight: 0,
                    // Keep final dimensions until store update propagates
                    currentWidth: finalWidth,
                    currentHeight: finalHeight,
                };
            });
            
            // CRITICAL: Force reset cursor MULTIPLE TIMES to ensure it sticks
            // Pass 1: Immediate (0ms) - enables drag immediately
            setTimeout(() => {
                try {
                    resizeRef.current.isActive = false;
                    resizeRef.current.rafId = null;
                    if (document.body) {
                        document.body.style.cursor = '';
                        document.body.style.pointerEvents = '';
                    }
                    if (document.documentElement) {
                        document.documentElement.style.cursor = '';
                    }
                    // Force state update again
                    setResizeState(prev => {
                        if (!prev.isResizing) return prev;
                        return { ...prev, isResizing: false };
                    });
                    console.log('[BoardItem] ResizeEnd: Pass 1 cleanup for item:', item.id);
                } catch (err) {
                    console.warn('[BoardItem] Error in pass 1 cleanup:', err);
                }
            }, 0);
            
            // Pass 2: After 10ms (catches any delayed state updates)
            setTimeout(() => {
                try {
                    resizeRef.current.isActive = false;
                    if (document.body) {
                        document.body.style.cursor = '';
                    }
                    setResizeState(prev => {
                        if (!prev.isResizing) return prev;
                        return { ...prev, isResizing: false };
                    });
                    console.log('[BoardItem] ResizeEnd: Pass 2 cleanup for item:', item.id);
                } catch (err) {
                    // Ignore
                }
            }, 10);
            
            // Pass 3: After 50ms (final safety net)
            setTimeout(() => {
                try {
                    resizeRef.current.isActive = false;
                    if (document.body) {
                        document.body.style.cursor = '';
                    }
                    if (window.N88StudioOS && window.N88StudioOS.activeResizes) {
                        window.N88StudioOS.activeResizes.delete(item.id);
                    }
                    console.log('[BoardItem] ResizeEnd: Pass 3 final cleanup for item:', item.id);
                } catch (err) {
                    // Ignore
                }
            }, 50);
            
            // Emit callback AFTER state is cleared (async to prevent blocking)
            if (finalWidth !== undefined && finalHeight !== undefined) {
                setTimeout(() => {
                    try {
                        // Emit layoutChanged callback to parent (triggers existing debounced save from 1.3.5)
                        if (onLayoutChanged) {
                            onLayoutChanged({
                                id: item.id,
                                x: item.x,
                                y: item.y,
                                width: finalWidth,
                                height: finalHeight,
                                displayMode: item.displayMode,
                            });
                        }
                    } catch (error) {
                        console.error('BoardItem: Error in resize end callback', error);
                    }
                }, 0);
            }
        };
        
        // NEW APPROACH: ALWAYS remove first, then attach (prevents accumulation after many resizes)
        // CRITICAL: This prevents listener accumulation that causes stuck state after many items
        try {
            // Always remove first (even if not attached - safe to call)
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleResizeEnd);
            window.removeEventListener('mouseup', handleResizeEnd);
            window.removeEventListener('blur', handleResizeEnd);
            window.removeEventListener('mouseleave', handleResizeEnd);
            document.removeEventListener('pointerup', handleResizeEnd);
            document.removeEventListener('pointerleave', handleResizeEnd);
            if (resizeRef.current.visibilityHandler) {
                document.removeEventListener('visibilitychange', resizeRef.current.visibilityHandler);
                resizeRef.current.visibilityHandler = null;
            }
        } catch (err) {
            console.warn('[BoardItem] Error removing old listeners:', err);
        }
        
        // Now attach fresh listeners (always, to ensure they're current)
        // Use stable handler to prevent listener accumulation
        document.addEventListener('mousemove', handleMouseMoveStable, { passive: true });
        document.addEventListener('mouseup', handleResizeEnd);
        window.addEventListener('mouseup', handleResizeEnd);
        window.addEventListener('blur', handleResizeEnd);
        window.addEventListener('mouseleave', handleResizeEnd);
        document.addEventListener('pointerup', handleResizeEnd);
        document.addEventListener('pointerleave', handleResizeEnd);
        
        // Visibility change handler (defined here so we can remove it)
        const handleVisibilityChange = () => {
            if (document.hidden && resizeRef.current.isActive) {
                console.log('[BoardItem] Visibility changed - ending resize');
                handleResizeEnd();
            }
        };
        document.addEventListener('visibilitychange', handleVisibilityChange);
        
        // Store handler reference for cleanup
        resizeRef.current.visibilityHandler = handleVisibilityChange;
        resizeRef.current.listenersAttached = true;
        console.log('[BoardItem] Listeners attached for item:', item.id);
        
        // Cleanup function - NEW SIMPLIFIED APPROACH using refs
        return () => {
            console.log('[BoardItem] Cleanup: effect re-running or unmounting, item:', item.id);
            
            // Mark as inactive IMMEDIATELY (using ref - always works)
            resizeRef.current.isActive = false;
            
            // Cancel RAF (using ref)
            if (resizeRef.current.rafId !== null) {
                cancelAnimationFrame(resizeRef.current.rafId);
                resizeRef.current.rafId = null;
            }
            
            // Remove listeners (simple cleanup - only what we added)
            // Use stable handler reference for proper removal
            try {
                document.removeEventListener('mousemove', handleMouseMoveStable);
                document.removeEventListener('mouseup', handleResizeEnd);
                window.removeEventListener('mouseup', handleResizeEnd);
                window.removeEventListener('blur', handleResizeEnd);
                window.removeEventListener('mouseleave', handleResizeEnd);
                document.removeEventListener('pointerup', handleResizeEnd);
                document.removeEventListener('pointerleave', handleResizeEnd);
                if (resizeRef.current.visibilityHandler) {
                    document.removeEventListener('visibilitychange', resizeRef.current.visibilityHandler);
                }
                resizeRef.current.listenersAttached = false;
                resizeRef.current.lastEvent = null; // Clear event reference
                console.log('[BoardItem] Cleanup: listeners removed');
            } catch (err) {
                console.warn('[BoardItem] Cleanup error:', err);
            }
            
            // Clear timeout
            if (resizeTimeoutRef.current) {
                clearTimeout(resizeTimeoutRef.current);
                resizeTimeoutRef.current = null;
            }
            
            // Reset cursor (CRITICAL for drag to work after cleanup)
            try {
                if (document.body) {
                    document.body.style.cursor = '';
                    document.body.style.pointerEvents = '';
                }
                if (document.documentElement) {
                    document.documentElement.style.cursor = '';
                }
            } catch (err) {
                console.warn('[BoardItem] Cleanup cursor error:', err);
            }
            
            // Force reset state if still resizing (CRITICAL: enables drag)
            setResizeState(prev => {
                if (!prev.isResizing) return prev;
                console.warn('[BoardItem] Cleanup: forcing end resize to enable drag');
                return {
                    isResizing: false, // CRITICAL: This enables drag
                    startX: 0,
                    startY: 0,
                    startWidth: 0,
                    startHeight: 0,
                    currentWidth: undefined,
                    currentHeight: undefined,
                };
            });
        };
    }, [resizeState.isResizing, resizeState.startX, resizeState.startY, resizeState.startWidth, resizeState.startHeight, item.id, item.x, item.y, item.displayMode, item.width, item.height, updateLayout, onLayoutChanged]);

    return (
        <motion.div
            layoutId={`board-item-${item.id}`}
            style={{
                position: 'absolute',
                x,
                y,
                width: currentWidth,
                height: currentHeight,
                zIndex: item.z,
                cursor: (resizeState.isResizing || resizeRef.current.isActive) ? 'nwse-resize' : 'grab',
            }}
            drag={!resizeState.isResizing && !resizeRef.current.isActive}
            dragMomentum={false}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onClick={handleClick}
            whileDrag={{ cursor: 'grabbing', scale: 1.05 }}
            transition={{
                layout: { duration: 0.3, ease: 'easeOut' },
            }}
        >
            {/* Main tile container - does not remount */}
            <div
                style={{
                    width: '100%',
                    height: '100%',
                    backgroundColor: '#ffffff',
                    border: '1px solid #e0e0e0',
                    borderRadius: '8px',
                    overflow: 'hidden',
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
                }}
            >
                {/* Hero image */}
                <div
                    style={{
                        width: '100%',
                        height: item.displayMode === 'photo_only' ? '100%' : '60%',
                        backgroundColor: '#e0e0e0',
                        backgroundImage: item.imageUrl ? `url(${item.imageUrl})` : 'none',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        position: 'relative',
                    }}
                >
                    {/* Toggle button for photo_only mode (overlay on image) */}
                    {item.displayMode === 'photo_only' && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                updateLayout(item.id, { displayMode: 'full' });
                                // Trigger save after animation completes (300ms + small buffer)
                                setTimeout(() => {
                                    if (onLayoutChanged) {
                                        onLayoutChanged({
                                            id: item.id,
                                            x: item.x,
                                            y: item.y,
                                            width: currentWidth,
                                            height: currentHeight,
                                            displayMode: 'full',
                                        });
                                    }
                                }, 350);
                            }}
                            style={{
                                position: 'absolute',
                                top: '8px',
                                right: '8px',
                                padding: '4px 8px',
                                fontSize: '11px',
                                cursor: 'pointer',
                                backgroundColor: '#0073aa',
                                color: '#fff',
                                border: 'none',
                                borderRadius: '3px',
                                zIndex: 10,
                                boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
                            }}
                        >
                            Show Full
                        </button>
                    )}
                    {/* Show item ID overlay only if no image */}
                    {!item.imageUrl && (
                        <div style={{
                            position: 'absolute',
                            top: '50%',
                            left: '50%',
                            transform: 'translate(-50%, -50%)',
                            backgroundColor: 'rgba(255,255,255,0.8)',
                            padding: '4px 8px',
                            borderRadius: '4px',
                            fontSize: '14px',
                            color: '#999',
                        }}>
                            Item {item.id}
                        </div>
                    )}
                </div>

                {/* Metadata section - fades in/out based on displayMode */}
                <AnimatePresence>
                    {item.displayMode === 'full' && (
                        <motion.div
                            key="metadata"
                            initial={{ opacity: 0, height: 0 }}
                            animate={{ opacity: 1, height: 'auto' }}
                            exit={{ opacity: 0, height: 0 }}
                            transition={{ duration: 0.3 }}
                            style={{
                                padding: '12px',
                                backgroundColor: '#ffffff',
                            }}
                        >
                            <div style={{ fontSize: '14px', fontWeight: 'bold' }}>
                                Item {item.id}
                            </div>
                            <div style={{ fontSize: '12px', color: '#666', marginTop: '4px' }}>
                                Position: {Math.round(item.x)}, {Math.round(item.y)}
                            </div>
                            {/* Toggle button for full mode */}
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    updateLayout(item.id, { displayMode: 'photo_only' });
                                    // Trigger save after animation completes (300ms + small buffer)
                                    setTimeout(() => {
                                        if (onLayoutChanged) {
                                        onLayoutChanged({
                                            id: item.id,
                                            x: item.x,
                                            y: item.y,
                                            width: currentWidth,
                                            height: currentHeight,
                                            displayMode: 'photo_only',
                                        });
                                        }
                                    }, 350);
                                }}
                                style={{
                                    marginTop: '8px',
                                    padding: '4px 8px',
                                    fontSize: '11px',
                                    cursor: 'pointer',
                                    backgroundColor: '#0073aa',
                                    color: '#fff',
                                    border: 'none',
                                    borderRadius: '3px',
                                }}
                            >
                                Toggle: Photo Only
                            </button>
                        </motion.div>
                    )}
                </AnimatePresence>
                
                {/* Resize handle (SE corner) */}
                <div
                    onMouseDown={handleResizeStart}
                    style={{
                        position: 'absolute',
                        bottom: 0,
                        right: 0,
                        width: '16px',
                        height: '16px',
                        cursor: 'nwse-resize',
                        backgroundColor: 'rgba(0, 0, 0, 0.1)',
                        borderTopLeftRadius: '8px',
                        zIndex: 20,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        pointerEvents: 'auto', // Ensure handle is clickable
                    }}
                >
                    {/* Visual indicator */}
                    <div
                        style={{
                            width: '8px',
                            height: '8px',
                            borderRight: '2px solid rgba(0, 0, 0, 0.3)',
                            borderBottom: '2px solid rgba(0, 0, 0, 0.3)',
                        }}
                    />
                </div>
            </div>
        </motion.div>
    );
};

export default BoardItem;

