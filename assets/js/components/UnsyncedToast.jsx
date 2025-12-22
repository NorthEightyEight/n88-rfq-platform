/**
 * UnsyncedToast Component
 * 
 * Milestone 1.3.5: Minimal toast notification for unsynced state
 * 
 * Shows a minimal, non-intrusive indicator when board layout is unsynced.
 */

import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';

/**
 * UnsyncedToast - Minimal toast notification
 * 
 * @param {Object} props
 * @param {boolean} props.unsynced - Whether board is unsynced
 * @param {Function} props.onDismiss - Callback when toast is dismissed
 */
const UnsyncedToast = ({ unsynced, onDismiss }) => {
    if (!unsynced) {
        return null;
    }

    return (
        <AnimatePresence>
            {unsynced && (
                <motion.div
                    initial={{ opacity: 0, y: -20 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -20 }}
                    transition={{ duration: 0.2 }}
                    style={{
                        position: 'fixed',
                        top: '20px',
                        right: '20px',
                        backgroundColor: '#ff9800',
                        color: '#fff',
                        padding: '12px 16px',
                        borderRadius: '4px',
                        boxShadow: '0 2px 8px rgba(0, 0, 0, 0.2)',
                        zIndex: 10000,
                        fontSize: '14px',
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        maxWidth: '300px',
                    }}
                >
                    <span>⚠️ Changes not saved</span>
                    {onDismiss && (
                        <button
                            onClick={onDismiss}
                            style={{
                                background: 'transparent',
                                border: 'none',
                                color: '#fff',
                                cursor: 'pointer',
                                fontSize: '18px',
                                padding: '0',
                                marginLeft: '8px',
                                lineHeight: '1',
                            }}
                        >
                            ×
                        </button>
                    )}
                </motion.div>
            )}
        </AnimatePresence>
    );
};

export default UnsyncedToast;

