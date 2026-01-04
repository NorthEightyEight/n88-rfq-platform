/**
 * ItemDetailModal Component
 * 
 * Commit 1.3.8: Item Detail Modal (Phase 1.2 Verification View)
 * 
 * Right-side drawer modal for viewing and editing item facts.
 * This is the source of truth for item metadata.
 */

import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';

// Access Zustand store from global namespace
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found');
});

/**
 * Normalize dimensions to cm
 */
const normalizeToCm = (value, unit) => {
    if (!value || isNaN(value)) return null;
    const num = parseFloat(value);
    switch (unit) {
        case 'mm': return num / 10;
        case 'cm': return num;
        case 'm': return num * 100;
        case 'in': return num * 2.54;
        default: return num;
    }
};

/**
 * Calculate CBM (Cubic Meters)
 */
const calculateCBM = (wCm, dCm, hCm) => {
    if (!wCm || !dCm || !hCm) return null;
    const wM = wCm / 100;
    const dM = dCm / 100;
    const hM = hCm / 100;
    return Math.round((wM * dM * hM) * 1000) / 1000; // Round to 3 decimals
};

/**
 * Infer sourcing_type from category and description
 */
const inferSourcingType = (category, description) => {
    const furnitureCategories = ['sofa', 'chair', 'table', 'desk', 'cabinet', 'shelf', 'bed', 'furniture'];
    const sourcingCategories = ['electronics', 'hardware', 'fixture', 'lighting', 'appliance'];
    
    const catLower = (category || '').toLowerCase();
    const descLower = (description || '').toLowerCase();
    
    // Check category match
    if (furnitureCategories.some(f => catLower.includes(f))) {
        return 'furniture';
    }
    if (sourcingCategories.some(s => catLower.includes(s))) {
        return 'global_sourcing';
    }
    
    // Keyword scan in description
    const furnitureKeywords = ['furniture', 'upholstery', 'cushion', 'fabric', 'wood', 'metal frame'];
    const sourcingKeywords = ['electronic', 'component', 'hardware', 'fixture', 'bulb', 'led'];
    
    if (furnitureKeywords.some(k => descLower.includes(k))) {
        return 'furniture';
    }
    if (sourcingKeywords.some(k => descLower.includes(k))) {
        return 'global_sourcing';
    }
    
    // Default to furniture
    return 'furniture';
};

/**
 * Assign timeline_type based on sourcing_type
 */
const assignTimelineType = (sourcingType) => {
    return sourcingType === 'furniture' ? 'furniture_6_step' : 'sourcing_4_step';
};

/**
 * ItemDetailModal - Right-side drawer for item facts
 */
const ItemDetailModal = ({ item, isOpen, onClose, onSave, priceRequested = false, onPriceRequest }) => {
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Form state - convert numbers to strings for input fields
    const [category, setCategory] = React.useState(item.category || item.item_type || '');
    const [description, setDescription] = React.useState(item.description || '');
    const [quantity, setQuantity] = React.useState(item.quantity ? String(item.quantity) : '');
    const [width, setWidth] = React.useState(item.dims?.w ? String(item.dims.w) : '');
    const [depth, setDepth] = React.useState(item.dims?.d ? String(item.dims.d) : '');
    const [height, setHeight] = React.useState(item.dims?.h ? String(item.dims.h) : '');
    const [unit, setUnit] = React.useState(item.dims?.unit || 'in');
    
    // Helper function to validate inspiration items
    const validateInspirationItem = (insp) => {
        if (!insp || typeof insp !== 'object') return false;
        const hasId = insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0;
        const url = insp.url ? String(insp.url).trim() : '';
        const hasValidUrl = url && 
            url.length > 0 &&
            (url.startsWith('http://') || url.startsWith('https://')) && 
            !url.startsWith('data:');
        return hasId || hasValidUrl;
    };
    
    // Filter initial inspiration to only include valid items
    const initialInspiration = (item.inspiration || []).filter(validateInspirationItem);
    const [inspiration, setInspiration] = React.useState(initialInspiration);
    const [isSaving, setIsSaving] = React.useState(false);
    const [isUploadingInspiration, setIsUploadingInspiration] = React.useState(false);
    
    // Update inspiration when item changes (if modal is reopened with different item)
    React.useEffect(() => {
        const validInspiration = (item.inspiration || []).filter(validateInspirationItem);
        setInspiration(validInspiration);
    }, [item.id, item.inspiration]);
    
    // Prevent body scroll when modal is open (fix double scrollbar issue)
    React.useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        // Cleanup: restore scroll when component unmounts
        return () => {
            document.body.style.overflow = '';
        };
    }, [isOpen]);
    
    // Get item ID and status
    const itemId = item.id || item.item_id || '';
    const itemStatus = item.status || 'Draft';
    
    // Get current user name (from WordPress or item owner)
    const currentUserName = window.N88StudioOS?.currentUser?.display_name || 
                           window.N88StudioOS?.currentUser?.name || 
                           item.owner_name || 
                           'User';
    
    // Computed values (read-only) - initialize from saved item data
    const [computedValues, setComputedValues] = React.useState({
        dimsCm: item.dims_cm || null,
        cbm: item.cbm || null,
        sourcingType: item.sourcing_type || null,
        timelineType: item.timeline_type || null,
    });
    
    // Recompute when dimensions change
    React.useEffect(() => {
        // If all dimensions are entered, compute CBM
        if (width && depth && height) {
            const wCm = normalizeToCm(width, unit);
            const dCm = normalizeToCm(depth, unit);
            const hCm = normalizeToCm(height, unit);
            
            if (wCm && dCm && hCm) {
                const cbm = calculateCBM(wCm, dCm, hCm);
                const sourcingType = inferSourcingType(category, description);
                const timelineType = assignTimelineType(sourcingType);
                
                setComputedValues({
                    dimsCm: { w_cm: wCm, d_cm: dCm, h_cm: hCm },
                    cbm,
                    sourcingType,
                    timelineType,
                });
            } else {
                // Dimensions entered but normalization failed
                const sourcingType = inferSourcingType(category, description);
                const timelineType = assignTimelineType(sourcingType);
                setComputedValues({
                    dimsCm: null,
                    cbm: null,
                    sourcingType,
                    timelineType,
                });
            }
        } else {
            // Not all dimensions entered - preserve saved computed values if they exist
            // This ensures saved CBM is displayed even if user hasn't entered dimensions yet
            const sourcingType = inferSourcingType(category, description);
            const timelineType = assignTimelineType(sourcingType);
            setComputedValues(prev => ({
                dimsCm: prev.dimsCm || item.dims_cm || null,
                cbm: prev.cbm !== null && prev.cbm !== undefined ? prev.cbm : (item.cbm !== null && item.cbm !== undefined ? item.cbm : null),
                sourcingType,
                timelineType,
            }));
        }
    }, [width, depth, height, unit, category, description]);
    
    // Handle save
    const handleSave = async () => {
        // Prevent save if uploads are in progress
        if (isUploadingInspiration) {
            alert('Please wait for image uploads to complete before saving.');
            return;
        }
        
        setIsSaving(true);
        
        try {
            // Validate and filter inspiration images - only keep ones with valid attachment IDs or URLs
            const validInspiration = inspiration.filter(insp => {
                if (!insp || typeof insp !== 'object') {
                    console.warn('Filtering out invalid inspiration image (not an object):', insp);
                    return false;
                }
                
                // Must have either an attachment ID or a valid URL (not base64 data URL)
                const hasId = insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0;
                const url = insp.url ? String(insp.url).trim() : '';
                const hasValidUrl = url && 
                    url.length > 0 &&
                    (url.startsWith('http://') || url.startsWith('https://')) && 
                    !url.startsWith('data:');
                
                if (!hasId && !hasValidUrl) {
                    console.warn('Filtering out invalid inspiration image (no valid ID or URL):', insp);
                    return false;
                }
                
                return true;
            }).map(insp => {
                // Normalize inspiration item structure - only include valid data
                const url = insp.url ? String(insp.url).trim() : '';
                const hasValidUrl = url && (url.startsWith('http://') || url.startsWith('https://'));
                
                return {
                    type: insp.type || 'image',
                    id: (insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0) ? Number(insp.id) : null,
                    url: hasValidUrl ? url : '',
                    title: insp.title || insp.filename || 'Reference image',
                };
            });
            
            console.log('Saving inspiration images:', validInspiration.length, 'valid images out of', inspiration.length, 'total');
            
            // Double-check: if we filtered out images, warn user
            if (inspiration.length > 0 && validInspiration.length === 0) {
                alert('Warning: All inspiration images were invalid and will not be saved. Please re-upload them.');
                setIsSaving(false);
                return;
            }
            
            // Prepare payload
            const dimsCm = computedValues.dimsCm;
            const payload = {
                category,
                description,
                quantity: quantity ? parseInt(quantity) : null,
                dims: {
                    w: width ? parseFloat(width) : null,
                    d: depth ? parseFloat(depth) : null,
                    h: height ? parseFloat(height) : null,
                    unit,
                },
                dims_cm: dimsCm,
                cbm: computedValues.cbm,
                sourcing_type: computedValues.sourcingType,
                timeline_type: computedValues.timelineType,
                inspiration: validInspiration, // Use validated inspiration array
            };
            
            // Update item in store
            updateLayout(item.id, payload);
            
            // Call onSave callback (handles AJAX and event logging)
            if (onSave) {
                await onSave(item.id, payload);
            }
            
            // Close modal
            onClose();
        } catch (error) {
            console.error('Error saving item facts:', error);
            alert('Failed to save item facts. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };
    
    // Handle inspiration image upload via file input - upload to WordPress media library
    const handleInspirationFileChange = async (e) => {
        const files = e.target.files;
        if (!files || files.length === 0) return;
        
        const imageFiles = Array.from(files).filter(file => file.type.startsWith('image/'));
        if (imageFiles.length === 0) {
            alert('Please select image files only.');
            e.target.value = '';
            return;
        }
        
        // Get AJAX URL and nonce
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardData?.nonce || window.n88?.nonce || '';
        
        if (!nonce) {
            console.error('Nonce not found. Available:', {
                n88BoardData: window.n88BoardData,
                n88: window.n88
            });
            alert('Security token missing. Please refresh the page and try again.');
            e.target.value = '';
            return;
        }
        
        // Set uploading state
        setIsUploadingInspiration(true);
        
        console.log('Uploading inspiration images:', imageFiles.length, 'files');
        
        try {
            // Upload each file to WordPress media library
            const uploadPromises = imageFiles.map(async (file) => {
                const formData = new FormData();
                formData.append('action', 'n88_upload_inspiration_image');
                formData.append('inspiration_image', file);
                formData.append('nonce', nonce);
                
                console.log('Uploading file:', file.name, 'Size:', file.size, 'Type:', file.type);
                
                try {
                    const response = await fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData,
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    console.log('Upload response:', data);
                    
                    // Strict validation: must have success, data, id (numeric > 0), and url (non-empty string)
                    if (data.success && 
                        data.data && 
                        data.data.id && 
                        Number.isInteger(Number(data.data.id)) && 
                        Number(data.data.id) > 0 &&
                        data.data.url && 
                        typeof data.data.url === 'string' && 
                        data.data.url.trim().length > 0 &&
                        (data.data.url.startsWith('http://') || data.data.url.startsWith('https://'))) {
                        console.log('Image uploaded successfully:', {
                            id: data.data.id,
                            url: data.data.url,
                            title: data.data.title
                        });
                        return {
                            type: 'image',
                            url: data.data.url.trim(),
                            id: Number(data.data.id),
                            title: data.data.title || data.data.filename || file.name,
                        };
                    } else {
                        const errorMsg = data.data?.message || 'Upload failed - missing or invalid data';
                        console.error('Failed to upload image:', errorMsg, {
                            success: data.success,
                            hasData: !!data.data,
                            hasId: !!(data.data && data.data.id),
                            idValue: data.data?.id,
                            hasUrl: !!(data.data && data.data.url),
                            urlValue: data.data?.url ? (data.data.url.substring(0, 50) + '...') : 'missing',
                            fullResponse: data
                        });
                        alert('Failed to upload ' + file.name + ': ' + errorMsg);
                        return null;
                    }
                } catch (error) {
                    console.error('Error uploading image:', error);
                    alert('Error uploading ' + file.name + ': ' + error.message);
                    return null;
                }
            });
            
            // Wait for all uploads to complete
            const uploadedImages = await Promise.all(uploadPromises);
            
            // Filter out failed uploads - only add images with valid IDs and URLs
            const validImages = uploadedImages.filter(img => {
                if (!img || typeof img !== 'object') return false;
                const hasId = img.id && Number.isInteger(Number(img.id)) && Number(img.id) > 0;
                const url = img.url ? String(img.url).trim() : '';
                const hasUrl = url && 
                    url.length > 0 &&
                    (url.startsWith('http://') || url.startsWith('https://')) && 
                    !url.startsWith('data:');
                const isValid = hasId && hasUrl;
                if (!isValid) {
                    console.warn('Filtering out invalid uploaded image:', img);
                }
                return isValid;
            });
            
            if (validImages.length > 0) {
                console.log('Adding', validImages.length, 'images to inspiration array');
                setInspiration([...inspiration, ...validImages]);
            } else {
                console.warn('No images were successfully uploaded');
                if (uploadedImages.length > 0) {
                    alert('No images were successfully uploaded. Please try again.');
                } else if (imageFiles.length > 0) {
                    alert('Failed to upload images. Please check your connection and try again.');
                }
            }
        } catch (error) {
            console.error('Error during upload process:', error);
            alert('Error uploading images: ' + error.message);
        } finally {
            setIsUploadingInspiration(false);
            // Reset input
            e.target.value = '';
        }
    };
    
    if (!isOpen) return null;
    
    return (
        <AnimatePresence>
            {isOpen && (
                <>
                    {/* Backdrop */}
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={onClose}
                        style={{
                            position: 'fixed',
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            backgroundColor: 'rgba(0, 0, 0, 0.5)',
                            zIndex: 10000,
                        }}
                    />
                    
                    {/* Drawer */}
                    <motion.div
                        initial={{ x: '100%' }}
                        animate={{ x: 0 }}
                        exit={{ x: '100%' }}
                        transition={{ type: 'spring', damping: 25, stiffness: 200 }}
                        style={{
                            position: 'fixed',
                            top: 0,
                            right: 0,
                            width: '480px',
                            maxWidth: '90vw',
                            height: '100vh',
                            backgroundColor: '#fff',
                            boxShadow: '-2px 0 10px rgba(0,0,0,0.2)',
                            zIndex: 10001,
                            display: 'flex',
                            flexDirection: 'column',
                            overflow: 'hidden',
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Header */}
                        <div style={{
                            padding: '12px 16px',
                            borderBottom: '1px solid #e0e0e0',
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                        }}>
                            <h2 style={{ margin: 0, fontSize: '16px', fontWeight: '600' }}>
                                Item Detail ({currentUserName})
                            </h2>
                            <button
                                onClick={onClose}
                                style={{
                                    background: 'none',
                                    border: 'none',
                                    fontSize: '20px',
                                    cursor: 'pointer',
                                    padding: '0',
                                    width: '24px',
                                    height: '24px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                }}
                            >
                                ×
                            </button>
                        </div>
                        
                        {/* Scrollable Content */}
                        <div style={{
                            flex: 1,
                            overflowY: 'auto',
                            padding: 0,
                        }}>
                            {/* Item ID and Status */}
                            <div style={{
                                padding: '12px 16px',
                                borderBottom: '1px solid #e0e0e0',
                                backgroundColor: '#f9f9f9',
                            }}>
                                <div style={{ marginBottom: '4px', fontSize: '14px', fontWeight: '600' }}>
                                    Item #{itemId}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>
                                    Status: {itemStatus}
                                </div>
                            </div>
                            
                            {/* Content Sections */}
                            <div style={{
                                padding: '12px 16px',
                            }}>
                            {/* Main Item Image */}
                            {(item.imageUrl || item.image_url || item.primary_image_url) && (
                                <div style={{ 
                                    marginBottom: '16px', 
                                    textAlign: 'center',
                                    padding: '8px',
                                    backgroundColor: '#f9f9f9',
                                    borderRadius: '4px',
                                    border: '1px solid #e0e0e0'
                                }}>
                                    <img 
                                        src={item.imageUrl || item.image_url || item.primary_image_url} 
                                        alt="Item main image"
                                        style={{
                                            maxWidth: '100%',
                                            maxHeight: '200px',
                                            width: 'auto',
                                            height: 'auto',
                                            borderRadius: '4px',
                                            objectFit: 'contain',
                                            border: '1px solid #ddd'
                                        }}
                                        onError={(e) => {
                                            e.target.style.display = 'none';
                                        }}
                                    />
                                </div>
                            )}
                            
                            {/* SECTION: Item Facts (Editable) */}
                            <div style={{ marginBottom: '16px' }}>
                                <h3 style={{ 
                                    fontSize: '12px', 
                                    fontWeight: '600', 
                                    marginBottom: '10px', 
                                    textTransform: 'uppercase', 
                                    color: '#333',
                                    borderBottom: '1px solid #e0e0e0',
                                    paddingBottom: '6px'
                                }}>
                                    SECTION: Item Facts
                                </h3>
                                
                                {/* Category */}
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Category
                                    </label>
                                    <select
                                        value={category}
                                        onChange={(e) => setCategory(e.target.value)}
                                        style={{
                                            width: '100%',
                                            padding: '6px 8px',
                                            border: '1px solid #ddd',
                                            borderRadius: '4px',
                                            fontSize: '12px',
                                        }}
                                    >
                                        <option value="">Select Category</option>
                                        <option value="furniture">Furniture</option>
                                        <option value="lighting">Lighting</option>
                                        <option value="accessory">Accessory</option>
                                        <option value="art">Art</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                {/* Description */}
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Description
                                    </label>
                                    <textarea
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        placeholder="Enter item description"
                                        rows={2}
                                        style={{
                                            width: '100%',
                                            padding: '6px 8px',
                                            border: '1px solid #ddd',
                                            borderRadius: '4px',
                                            fontSize: '12px',
                                            fontFamily: 'inherit',
                                            resize: 'vertical',
                                        }}
                                    />
                                </div>
                                
                                {/* Quantity */}
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Quantity
                                    </label>
                                    <input
                                        type="number"
                                        value={quantity}
                                        onChange={(e) => setQuantity(e.target.value)}
                                        placeholder="Enter quantity"
                                        min="1"
                                        style={{
                                            width: '100%',
                                            padding: '6px 8px',
                                            border: '1px solid #ddd',
                                            borderRadius: '4px',
                                            fontSize: '12px',
                                        }}
                                    />
                                </div>
                                
                                {/* Dimensions */}
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Dimensions
                                    </label>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: '6px', alignItems: 'end' }}>
                                        <div>
                                            <label style={{ display: 'block', fontSize: '10px', marginBottom: '2px', color: '#999' }}>W</label>
                                            <input
                                                type="number"
                                                value={width}
                                                onChange={(e) => setWidth(e.target.value)}
                                                placeholder="Width"
                                                style={{
                                                    width: '100%',
                                                    padding: '6px 8px',
                                                    border: '1px solid #ddd',
                                                    borderRadius: '4px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                        </div>
                                        <div>
                                            <label style={{ display: 'block', fontSize: '10px', marginBottom: '2px', color: '#999' }}>D</label>
                                            <input
                                                type="number"
                                                value={depth}
                                                onChange={(e) => setDepth(e.target.value)}
                                                placeholder="Depth"
                                                style={{
                                                    width: '100%',
                                                    padding: '6px 8px',
                                                    border: '1px solid #ddd',
                                                    borderRadius: '4px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                        </div>
                                        <div>
                                            <label style={{ display: 'block', fontSize: '10px', marginBottom: '2px', color: '#999' }}>H</label>
                                            <input
                                                type="number"
                                                value={height}
                                                onChange={(e) => setHeight(e.target.value)}
                                                placeholder="Height"
                                                style={{
                                                    width: '100%',
                                                    padding: '6px 8px',
                                                    border: '1px solid #ddd',
                                                    borderRadius: '4px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                        </div>
                                        <div>
                                            <label style={{ display: 'block', fontSize: '10px', marginBottom: '2px', color: '#999' }}>Unit</label>
                                            <select
                                                value={unit}
                                                onChange={(e) => setUnit(e.target.value)}
                                                style={{
                                                    width: '100%',
                                                    padding: '6px 8px',
                                                    border: '1px solid #ddd',
                                                    borderRadius: '4px',
                                                    fontSize: '12px',
                                                }}
                                            >
                                                <option value="mm">mm</option>
                                                <option value="cm">cm</option>
                                                <option value="m">m</option>
                                                <option value="in">in</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            {/* SECTION: System Intelligence (Read-Only) */}
                            <div style={{ marginBottom: '16px' }}>
                                <h3 style={{ 
                                    fontSize: '12px', 
                                    fontWeight: '600', 
                                    marginBottom: '10px', 
                                    textTransform: 'uppercase', 
                                    color: '#333',
                                    borderBottom: '1px solid #e0e0e0',
                                    paddingBottom: '6px'
                                }}>
                                    SECTION: System Intelligence
                                </h3>
                                
                                {/* Normalized (cm) */}
                                <div style={{ marginBottom: '10px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Normalized (cm)
                                    </label>
                                    {computedValues.dimsCm ? (
                                        <div style={{ 
                                            padding: '6px 8px', 
                                            backgroundColor: '#f5f5f5', 
                                            borderRadius: '4px', 
                                            fontSize: '12px',
                                            border: '1px solid #e0e0e0',
                                            fontFamily: 'monospace'
                                        }}>
                                            W {computedValues.dimsCm.w_cm.toFixed(1)} D {computedValues.dimsCm.d_cm.toFixed(1)} H {computedValues.dimsCm.h_cm.toFixed(1)}
                                        </div>
                                    ) : (
                                        <div style={{ 
                                            padding: '6px 8px', 
                                            backgroundColor: '#f5f5f5', 
                                            borderRadius: '4px', 
                                            fontSize: '12px',
                                            color: '#999',
                                            border: '1px solid #e0e0e0',
                                            fontFamily: 'monospace'
                                        }}>
                                            W — D — H —
                                        </div>
                                    )}
                                </div>
                                
                                {/* CBM */}
                                <div style={{ marginBottom: '10px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        CBM
                                    </label>
                                    {computedValues.cbm !== null ? (
                                        <div style={{ 
                                            padding: '6px 8px', 
                                            backgroundColor: '#f5f5f5', 
                                            borderRadius: '4px', 
                                            fontSize: '12px',
                                            border: '1px solid #e0e0e0'
                                        }}>
                                            CBM: {computedValues.cbm}
                                        </div>
                                    ) : (
                                        <div style={{ 
                                            padding: '6px 8px', 
                                            backgroundColor: '#f5f5f5', 
                                            borderRadius: '4px', 
                                            fontSize: '12px',
                                            color: '#999',
                                            border: '1px solid #e0e0e0'
                                        }}>
                                            CBM: —
                                        </div>
                                    )}
                                </div>
                                
                                {/* Sourcing Type */}
                                <div style={{ marginBottom: '10px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Sourcing Type
                                    </label>
                                    <div style={{ 
                                        padding: '6px 8px', 
                                        backgroundColor: '#f5f5f5', 
                                        borderRadius: '4px', 
                                        fontSize: '12px',
                                        border: '1px solid #e0e0e0'
                                    }}>
                                        {computedValues.sourcingType || 'furniture'}
                                    </div>
                                </div>
                                
                                {/* Timeline Type */}
                                <div style={{ marginBottom: '10px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Timeline Type
                                    </label>
                                    <div style={{ 
                                        padding: '6px 8px', 
                                        backgroundColor: '#f5f5f5', 
                                        borderRadius: '4px', 
                                        fontSize: '12px',
                                        border: '1px solid #e0e0e0'
                                    }}>
                                        {computedValues.timelineType || 'furniture_6_step'}
                                    </div>
                                </div>
                                
                                {/* Reason */}
                                <div style={{ marginBottom: '10px' }}>
                                    <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px', color: '#666' }}>
                                        Reason
                                </label>
                                    <div style={{ 
                                        padding: '6px 8px', 
                                        backgroundColor: '#f5f5f5', 
                                        borderRadius: '4px', 
                                        fontSize: '12px',
                                        border: '1px solid #e0e0e0'
                                    }}>
                                        default
                                    </div>
                                </div>
                            </div>
                            
                            {/* SECTION: Inspiration / Reference (Phase 1.2) */}
                            <div style={{ marginBottom: '16px' }}>
                                <h3 style={{ 
                                    fontSize: '12px', 
                                    fontWeight: '600', 
                                    marginBottom: '10px', 
                                    textTransform: 'uppercase', 
                                    color: '#333',
                                    borderBottom: '1px solid #e0e0e0',
                                    paddingBottom: '6px'
                                }}>
                                    SECTION: Inspiration / Reference
                                </h3>
                                
                                {inspiration.length > 0 ? (
                                    <div style={{ 
                                        width: '100%',
                                        minHeight: inspiration.length === 1 ? '100px' : '80px',
                                        backgroundColor: '#f0f0f0',
                                        border: '1px solid #e0e0e0',
                                        borderRadius: '4px',
                                        padding: '6px',
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '6px',
                                        alignItems: 'stretch'
                                    }}>
                                        {inspiration.map((insp, idx) => {
                                            const imageCount = inspiration.length;
                                            let containerStyle = {};
                                            
                                            if (imageCount === 1) {
                                                containerStyle = {
                                                    position: 'relative',
                                                    width: '100%',
                                                    height: '100px',
                                                    flex: '0 0 100%'
                                                };
                                            } else if (imageCount === 2) {
                                                containerStyle = {
                                                    position: 'relative',
                                                    width: 'calc(50% - 3px)',
                                                    height: '80px',
                                                    flex: '0 0 calc(50% - 3px)'
                                                };
                                            } else {
                                                containerStyle = {
                                                    position: 'relative',
                                                    width: 'calc(33.333% - 4px)',
                                                    height: '80px',
                                                    flex: '0 0 calc(33.333% - 4px)'
                                                };
                                            }
                                            
                                            return (
                                                <div key={idx} style={containerStyle}>
                                                    {insp.url && (
                                                        <img 
                                                            src={insp.url} 
                                                            alt={insp.title || 'Reference'} 
                                                            style={{ 
                                                                width: '100%',
                                                                height: '100%',
                                                                objectFit: 'contain',
                                                                border: '1px solid #ddd',
                                                                borderRadius: '4px',
                                                                backgroundColor: '#fff'
                                                            }} 
                                                        />
                                                    )}
                                                    <button
                                                        onClick={() => setInspiration(inspiration.filter((_, i) => i !== idx))}
                                                        style={{
                                                            position: 'absolute',
                                                            top: '5px',
                                                            right: '5px',
                                                            background: '#d32f2f',
                                                            color: '#fff',
                                                            border: 'none',
                                                            borderRadius: '50%',
                                                            width: '24px',
                                                            height: '24px',
                                                            cursor: 'pointer',
                                                            fontSize: '16px',
                                                            lineHeight: '24px',
                                                            padding: 0,
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            boxShadow: '0 2px 4px rgba(0,0,0,0.2)'
                                                        }}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div style={{ 
                                        width: '100%',
                                        height: '100px',
                                        backgroundColor: '#f0f0f0',
                                        border: '2px dashed #ccc',
                                        borderRadius: '4px',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        color: '#999',
                                        fontSize: '12px'
                                    }}>
                                        <div style={{ textAlign: 'center', color: '#999' }}>
                                            <div style={{ fontSize: '32px', marginBottom: '6px', opacity: 0.3 }}>📷</div>
                                            <div>No reference images</div>
                                        </div>
                                    </div>
                                )}
                                
                                <input
                                    type="file"
                                    id="inspiration-file-input"
                                    accept="image/*"
                                    multiple
                                    onChange={handleInspirationFileChange}
                                    style={{ display: 'none' }}
                                    disabled={isUploadingInspiration}
                                />
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (isUploadingInspiration) return;
                                        const input = document.getElementById('inspiration-file-input');
                                        if (input) input.click();
                                    }}
                                    disabled={isUploadingInspiration}
                                    style={{
                                        marginTop: '8px',
                                        padding: '6px 12px',
                                        backgroundColor: isUploadingInspiration ? '#ccc' : '#f0f0f0',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        cursor: isUploadingInspiration ? 'not-allowed' : 'pointer',
                                        fontSize: '11px',
                                        opacity: isUploadingInspiration ? 0.6 : 1,
                                    }}
                                >
                                    {isUploadingInspiration ? '⏳ Uploading...' : '+ Add Reference Image'}
                                </button>
                            </div>
                            
                            {/* SECTION: Actions */}
                            <div style={{ marginBottom: '16px' }}>
                                <h3 style={{ 
                                    fontSize: '12px', 
                                    fontWeight: '600', 
                                    marginBottom: '10px', 
                                    textTransform: 'uppercase', 
                                    color: '#333',
                                    borderBottom: '1px solid #e0e0e0',
                                    paddingBottom: '6px'
                                }}>
                                    SECTION: Actions
                                </h3>
                                <div style={{ display: 'flex', flexDirection: 'row', gap: '8px' }}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (onPriceRequest && !priceRequested) {
                                                onPriceRequest();
                                            }
                                        }}
                                        disabled={priceRequested}
                                        style={{
                                            flex: 1,
                                            padding: '8px 12px',
                                            fontWeight: '500',
                                            cursor: priceRequested ? 'not-allowed' : 'pointer',
                                            backgroundColor: priceRequested ? '#e0e0e0' : '#0073aa',
                                            color: priceRequested ? '#999' : '#fff',
                                            border: `1px solid ${priceRequested ? '#ccc' : '#0073aa'}`,
                                            borderRadius: '4px',
                                            fontSize: '12px',
                                            textAlign: 'center',
                                            transition: 'all 0.2s',
                                            boxShadow: priceRequested ? 'none' : '0 2px 4px rgba(0,0,0,0.1)',
                                        }}
                                    >
                                        {priceRequested ? 'Price Requested' : 'Request Price'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            // Commit 2.3.4: Open RFQ submission modal
                                            var itemIdToSubmit = itemId || item.id || item.item_id;
                                            console.log('Request Quote clicked for item:', itemIdToSubmit);
                                            if (window.openRfqSubmissionModal) {
                                                window.openRfqSubmissionModal([itemIdToSubmit]);
                                            } else {
                                                console.error('openRfqSubmissionModal function not found. Make sure you are on the board page.');
                                                alert('RFQ submission is only available on the board page. Please navigate to your workspace board.');
                                            }
                                        }}
                                        style={{
                                            flex: 1,
                                            padding: '8px 12px',
                                            fontWeight: '500',
                                            backgroundColor: '#0073aa',
                                            color: '#fff',
                                            border: '1px solid #0073aa',
                                            borderRadius: '4px',
                                            cursor: 'pointer',
                                            fontSize: '12px',
                                            textAlign: 'center',
                                            transition: 'all 0.2s',
                                            boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                                        }}
                                        onMouseEnter={(e) => {
                                            e.target.style.backgroundColor = '#005a87';
                                        }}
                                        onMouseLeave={(e) => {
                                            e.target.style.backgroundColor = '#0073aa';
                                        }}
                                    >
                                        Request Quote
                                </button>
                                </div>
                            </div>
                            
                            {/* SECTION: Thread */}
                            <div style={{ marginBottom: '16px' }}>
                                <h3 style={{ 
                                    fontSize: '12px', 
                                    fontWeight: '600', 
                                    marginBottom: '10px', 
                                    textTransform: 'uppercase', 
                                    color: '#333',
                                    borderBottom: '1px solid #e0e0e0',
                                    paddingBottom: '6px'
                                }}>
                                    SECTION: Thread
                                </h3>
                                <div style={{ 
                                    padding: '8px',
                                    backgroundColor: '#f5f5f5',
                                    borderRadius: '4px',
                                    fontSize: '12px',
                                    color: '#666',
                                    border: '1px solid #e0e0e0'
                                }}>
                                    — Comments/admin replies / approvals
                                </div>
                            </div>
                            </div>
                                                    </div>
                        
                        {/* Footer Actions */}
                        <div style={{
                            padding: '12px 16px',
                            borderTop: '1px solid #e0e0e0',
                            display: 'flex',
                            gap: '8px',
                            justifyContent: 'flex-end',
                        }}>
                            <button
                                onClick={onClose}
                                style={{
                                    padding: '6px 12px',
                                    backgroundColor: '#f0f0f0',
                                    border: '1px solid #ddd',
                                    borderRadius: '4px',
                                    cursor: 'pointer',
                                    fontSize: '12px',
                                }}
                            >
                                Close
                            </button>
                            <button
                                onClick={handleSave}
                                disabled={isSaving || isUploadingInspiration}
                                style={{
                                    padding: '6px 12px',
                                    backgroundColor: '#0073aa',
                                    color: '#fff',
                                    border: 'none',
                                    borderRadius: '4px',
                                    cursor: (isSaving || isUploadingInspiration) ? 'not-allowed' : 'pointer',
                                    fontSize: '12px',
                                    opacity: (isSaving || isUploadingInspiration) ? 0.6 : 1,
                                }}
                            >
                                {isUploadingInspiration ? 'Uploading images...' : (isSaving ? 'Saving...' : 'Save Item Facts')}
                            </button>
                        </div>
                    </motion.div>
                </>
            )}
        </AnimatePresence>
    );
};

export default ItemDetailModal;

