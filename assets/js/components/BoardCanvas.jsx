/**
 * Board Canvas Component
 * 
 * Milestone 1.3.4: Canvas Shell + BoardItem (Drag + Z + Morph)
 * 
 * Fixed viewport canvas that serves as positioning context for board items.
 */

import React from 'react';
import BoardItem from './BoardItem';

// Access Zustand store from global namespace (WordPress UMD pattern)
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found. Ensure useBoardStore.js is loaded before this component.');
});

/**
 * BoardCanvas - Fixed viewport canvas container
 * 
 * @param {Object} props
 * @param {Function} props.onLayoutChanged - Callback when layout changes (id, x, y, width, height, displayMode)
 */
const BoardCanvas = ({ onLayoutChanged }) => {
    const items = useBoardStore((state) => state.items);

    return (
        <div
            style={{
                position: 'fixed',
                top: 0,
                left: 0,
                width: '100vw',
                height: '100vh',
                overflow: 'hidden',
                backgroundColor: '#f5f5f5',
            }}
        >
            {items.map((item) => (
                <BoardItem
                    key={item.id}
                    item={item}
                    onLayoutChanged={onLayoutChanged}
                />
            ))}
        </div>
    );
};

export default BoardCanvas;

