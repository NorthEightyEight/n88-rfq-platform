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
                {/* Hero image placeholder */}
                <div
                    style={{
                        width: '100%',
                        height: item.displayMode === 'photo_only' ? '100%' : '60%',
                        backgroundColor: '#f0f0f0',
                        backgroundImage: 'url(https://via.placeholder.com/400x300)',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                    }}
                />

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
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>
        </motion.div>
    );
};

export default BoardItem;

