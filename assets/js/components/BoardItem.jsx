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

    // Update motion values when item position changes from store
    React.useEffect(() => {
        x.set(item.x);
        y.set(item.y);
    }, [item.x, item.y, x, y]);

    const handleDragStart = () => {
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

        // Emit layoutChanged callback to parent (NO persistence, NO API calls)
        if (onLayoutChanged) {
            onLayoutChanged({
                id: item.id,
                x: newX,
                y: newY,
                width: item.width,
                height: item.height,
                displayMode: item.displayMode,
            });
        }
    };

    return (
        <motion.div
            layoutId={`board-item-${item.id}`}
            style={{
                position: 'absolute',
                x,
                y,
                width: item.width,
                height: item.height,
                zIndex: item.z,
                cursor: 'grab',
            }}
            drag
            dragMomentum={false}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
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
                                            width: item.width,
                                            height: item.height,
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
                                                width: item.width,
                                                height: item.height,
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
            </div>
        </motion.div>
    );
};

export default BoardItem;

