/**
 * N88 RFQ PDF Extraction Handler
 * Handles PDF upload, extraction preview, and item import
 */

(function() {
    'use strict';

    const N88PDFExtraction = {
        initialized: false,
        currentExtractionData: null,

        /**
         * Initialize PDF extraction handlers
         */
        init: function() {
            if (this.initialized) {
                return;
            }

            // Initialize when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    this.setupHandlers();
                });
            } else {
                this.setupHandlers();
            }

            this.initialized = true;
        },

        /**
         * Setup all event handlers
         */
        setupHandlers: function() {
            // Entry mode toggle handlers
            this.setupEntryModeToggle();
            
            // PDF upload handlers (will be re-initialized when PDF mode is shown)
            this.setupPDFUploadHandlers();
            
            // Extraction confirmation handlers
            this.setupExtractionHandlers();
        },

        /**
         * Setup entry mode toggle (Manual vs PDF)
         */
        setupEntryModeToggle: function() {
            const entryModeRadios = document.querySelectorAll('.entry-mode-radio');
            
            entryModeRadios.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    this.toggleEntryMode(e.target.value);
                });
                
                radio.addEventListener('click', (e) => {
                    setTimeout(() => {
                        this.toggleEntryMode(e.target.value);
                    }, 10);
                });
            });
        },

        /**
         * Toggle between manual entry and PDF upload modes
         */
        toggleEntryMode: function(mode) {
            const manualMode = document.getElementById('manual-entry-mode');
            const pdfMode = document.getElementById('pdf-upload-mode');
            const skipCheckbox = document.getElementById('skip-manual-entry');
            const isSkipChecked = skipCheckbox && skipCheckbox.checked;
            
            console.log('Toggle entry mode to:', mode);
            
            if (mode === 'manual') {
                if (manualMode) {
                    manualMode.style.display = 'block';
                }
                if (pdfMode && !isSkipChecked) {
                    pdfMode.style.display = 'none';
                }
                
                // Show Files sections only if skip checkbox is not checked
                const filesSections = document.querySelectorAll('.n88-item-files-section');
                filesSections.forEach(section => {
                    section.style.display = isSkipChecked ? 'none' : 'block';
                });
            } else if (mode === 'pdf') {
                if (manualMode) {
                    manualMode.style.display = 'none';
                }
                if (pdfMode) {
                    pdfMode.style.display = 'block';
                    
                    // Re-initialize PDF upload handlers when PDF mode is shown
                    setTimeout(() => {
                        this.setupPDFUploadHandlers();
                    }, 100);
                    
                    // Remove any existing warning (we now handle project creation automatically)
                    const existingWarning = pdfMode.querySelector('.n88-pdf-warning');
                    if (existingWarning) {
                        existingWarning.remove();
                    }
                }
                
                // Hide Files sections when PDF mode is selected
                const filesSections = document.querySelectorAll('.n88-item-files-section');
                filesSections.forEach(section => {
                    section.style.display = 'none';
                });
            }
        },

        /**
         * Setup PDF upload handlers (dropzone and file input)
         */
        setupPDFUploadHandlers: function() {
            const pdfDropzone = document.getElementById('n88-pdf-dropzone');
            const pdfInput = document.getElementById('pdf_file_upload');
            
            if (!pdfDropzone || !pdfInput) {
                console.log('PDF upload elements not found');
                return;
            }
            
            // Remove existing listeners by cloning (but preserve the input)
            const inputParent = pdfInput.parentNode;
            const newDropzone = pdfDropzone.cloneNode(true);
            
            // Get the input from the cloned dropzone
            const clonedInput = newDropzone.querySelector('#pdf_file_upload');
            
            // Replace dropzone but keep original input
            pdfDropzone.parentNode.replaceChild(newDropzone, pdfDropzone);
            
            // Re-insert the original input (so it maintains its state)
            if (clonedInput) {
                clonedInput.remove();
            }
            newDropzone.appendChild(pdfInput);
            
            // Click handler - make entire dropzone clickable
            newDropzone.addEventListener('click', (e) => {
                // Don't trigger if clicking the input itself
                if (e.target === pdfInput) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                pdfInput.click();
            });
            
            // Prevent content from blocking clicks
            const content = newDropzone.querySelector('.n88-pdf-dropzone-content');
            if (content) {
                content.style.pointerEvents = 'none';
            }
            
            // Also make sure dropzone itself is clickable
            newDropzone.style.cursor = 'pointer';
            
            // Drag and drop handlers
            newDropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                newDropzone.style.borderColor = '#007cba';
                newDropzone.style.background = '#e6f2ff';
            });
            
            newDropzone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                newDropzone.style.borderColor = '#ccc';
                newDropzone.style.background = 'white';
            });
            
            newDropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                newDropzone.style.borderColor = '#ccc';
                newDropzone.style.background = 'white';
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (this.isValidPDF(file)) {
                        this.handlePDFUpload(file);
                    } else {
                        alert('Please upload a PDF file only.');
                    }
                }
            });
            
            // File input change handler
            pdfInput.addEventListener('change', (e) => {
                if (e.target.files && e.target.files.length > 0) {
                    const file = e.target.files[0];
                    if (this.isValidPDF(file)) {
                        this.handlePDFUpload(file);
                    } else {
                        alert('Please upload a PDF file only.');
                        e.target.value = '';
                    }
                }
            });
            
            console.log('PDF upload handlers initialized');
        },

        /**
         * Check if file is a valid PDF
         */
        isValidPDF: function(file) {
            return file.type === 'application/pdf' || 
                   file.name.toLowerCase().endsWith('.pdf');
        },

        /**
         * Handle PDF file upload
         */
        handlePDFUpload: function(file) {
            console.log('PDF file selected:', file.name);
            
            let projectId = this.getProjectId();
            
            // Show progress
            const progressDiv = document.getElementById('n88-pdf-upload-progress');
            if (progressDiv) {
                progressDiv.style.display = 'block';
                progressDiv.querySelector('.progress-text').textContent = projectId 
                    ? 'Extracting items from PDF...' 
                    : 'Creating project and extracting items...';
            }
            
            // If no project_id, create a draft project first
            if (!projectId) {
                this.createDraftProject(file)
                    .then(newProjectId => {
                        console.log('Project creation resolved with ID:', newProjectId);
                        if (newProjectId && newProjectId > 0) {
                            projectId = parseInt(newProjectId, 10);
                            console.log('Using project_id:', projectId);
                            
                            // Update the hidden input
                            const projectIdInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
                            if (projectIdInput) {
                                projectIdInput.value = projectId;
                                console.log('Updated project_id input to:', projectId);
                            }
                            
                            // Update URL if possible (optional)
                            if (window.history && window.history.replaceState) {
                                const url = new URL(window.location);
                                url.searchParams.set('project_id', projectId);
                                window.history.replaceState({}, '', url);
                            }
                            
                            // Small delay to ensure project is saved and database is updated
                            setTimeout(() => {
                                // Verify project exists before proceeding
                                this.verifyProjectExists(projectId)
                                    .then(() => {
                                        // Proceed with extraction
                                        this.uploadPDFForExtraction(file, projectId);
                                    })
                                    .catch(error => {
                                        console.error('Project verification failed:', error);
                                        const progressDiv = document.getElementById('n88-pdf-upload-progress');
                                        if (progressDiv) {
                                            progressDiv.style.display = 'none';
                                        }
                                        alert('Project was created but could not be verified. Please refresh the page and try again.');
                                    });
                            }, 500); // Increased delay to 500ms
                        } else {
                            if (progressDiv) {
                                progressDiv.style.display = 'none';
                            }
                            alert('Failed to create project. Please fill in the required fields and try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating project:', error);
                        if (progressDiv) {
                            progressDiv.style.display = 'none';
                        }
                        alert('Error creating project. Please fill in the required fields (Project Name, Project Type, Timeline, Budget Range, Email) and try again.');
                    });
            } else {
                // Project exists, proceed with extraction
                this.uploadPDFForExtraction(file, projectId);
            }
        },

        /**
         * Create a draft project with form data
         */
        createDraftProject: function(pdfFile) {
            return new Promise((resolve, reject) => {
                // Get form data
                const projectName = document.getElementById('project_name')?.value?.trim() || 'PDF Extraction Project';
                const projectType = document.getElementById('project_type')?.value?.trim() || 'Other';
                const timeline = document.getElementById('timeline')?.value?.trim() || 'TBD';
                const budgetRange = document.getElementById('budget_range')?.value?.trim() || 'TBD';
                const email = document.getElementById('email')?.value?.trim() || '';
                
                // Validate minimum required fields
                if (!projectName || !projectType || !timeline || !budgetRange || !email) {
                    reject(new Error('Please fill in required fields: Project Name, Project Type, Timeline, Budget Range, and Email'));
                    return;
                }
                
                // Get nonce - try multiple possible names
                let nonce = '';
                if (typeof n88 !== 'undefined' && n88.nonce) {
                    nonce = n88.nonce;
                } else {
                    // Try different possible nonce field names
                    const nonceInput = document.querySelector('input[name="n88_rfq_nonce"]') 
                        || document.querySelector('input[name="n88-rfq-nonce"]')
                        || document.querySelector('input[name="_wpnonce"]');
                    if (nonceInput) {
                        nonce = nonceInput.value;
                        console.log('Found nonce from input:', nonceInput.name);
                    } else {
                        console.warn('No nonce found in form');
                    }
                }
                
                if (!nonce) {
                    reject(new Error('Security nonce not found. Please refresh the page and try again.'));
                    return;
                }
                
                // Get form type
                const formType = document.querySelector('input[name="form_type"]')?.value || 'rfq';
                
                // Create FormData for project creation
                const formData = new FormData();
                formData.append('action', 'n88_submit_project');
                formData.append('submit_type', 'draft');
                formData.append('form_type', formType);
                formData.append('project_name', projectName);
                formData.append('project_type', projectType);
                formData.append('timeline', timeline);
                formData.append('budget_range', budgetRange);
                formData.append('email', email);
                formData.append('n88_rfq_nonce', nonce);
                
                // Add other optional fields if they exist
                const companyName = document.getElementById('company_name')?.value?.trim();
                const contactName = document.getElementById('contact_name')?.value?.trim();
                const phone = document.getElementById('phone')?.value?.trim();
                const location = document.getElementById('location')?.value?.trim();
                
                if (companyName) formData.append('company_name', companyName);
                if (contactName) formData.append('contact_name', contactName);
                if (phone) formData.append('phone', phone);
                if (location) formData.append('location', location);
                
                // Get AJAX URL
                const ajaxUrl = (typeof n88 !== 'undefined' && n88.ajaxUrl) 
                    ? n88.ajaxUrl 
                    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
                
                // Submit via admin-post.php (same as form submission)
                const submitUrl = window.location.origin + '/wp-admin/admin-post.php';
                
                fetch(submitUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => {
                    // admin-post.php redirects, so we need to get project_id from redirect URL or response
                    // For now, we'll extract from the response or use a different approach
                    return response.text();
                })
                .then(html => {
                    // Try to extract project_id from redirect or create via AJAX instead
                    // Actually, let's use a custom AJAX endpoint that returns project_id
                    return this.createDraftProjectViaAJAX(projectName, projectType, timeline, budgetRange, email, formType, nonce);
                })
                .then(projectId => {
                    resolve(projectId);
                })
                .catch(error => {
                    reject(error);
                });
            });
        },

        /**
         * Create draft project via AJAX (returns project_id)
         */
        createDraftProjectViaAJAX: function(projectName, projectType, timeline, budgetRange, email, formType, nonce) {
            return new Promise((resolve, reject) => {
                console.log('Creating draft project with:', {
                    projectName,
                    projectType,
                    timeline,
                    budgetRange,
                    email,
                    formType
                });
                
                const formData = new URLSearchParams({
                    action: 'n88_create_draft_for_pdf',
                    project_name: projectName,
                    project_type: projectType,
                    timeline: timeline,
                    budget_range: budgetRange,
                    email: email,
                    form_type: formType,
                    nonce: nonce
                });
                
                // Add optional fields
                const companyName = document.getElementById('company_name')?.value?.trim();
                const contactName = document.getElementById('contact_name')?.value?.trim();
                const phone = document.getElementById('phone')?.value?.trim();
                const location = document.getElementById('location')?.value?.trim();
                
                if (companyName) formData.append('company_name', companyName);
                if (contactName) formData.append('contact_name', contactName);
                if (phone) formData.append('phone', phone);
                if (location) formData.append('location', location);
                
                const ajaxUrl = (typeof n88 !== 'undefined' && n88.ajaxUrl) 
                    ? n88.ajaxUrl 
                    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
                
                console.log('Sending AJAX request to:', ajaxUrl);
                
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Project creation response:', data);
                    if (data.success && data.data && data.data.project_id) {
                        const newProjectId = parseInt(data.data.project_id, 10);
                        if (newProjectId > 0) {
                            console.log('Project created successfully with ID:', newProjectId);
                            resolve(newProjectId);
                        } else {
                            console.error('Invalid project ID returned:', newProjectId);
                            reject(new Error('Invalid project ID returned from server'));
                        }
                    } else {
                        const errorMsg = data.data?.message || data.message || 'Failed to create project';
                        console.error('Project creation failed:', errorMsg, data);
                        reject(new Error(errorMsg));
                    }
                })
                .catch(error => {
                    console.error('Project creation AJAX error:', error);
                    reject(error);
                });
            });
        },

        /**
         * Verify project exists on server
         */
        verifyProjectExists: function(projectId) {
            return new Promise((resolve, reject) => {
                const formData = new URLSearchParams({
                    action: 'n88_verify_project',
                    project_id: String(projectId)
                });
                
                // Get nonce
                let nonce = '';
                if (typeof n88 !== 'undefined' && n88.nonce) {
                    nonce = n88.nonce;
                } else {
                    const nonceInput = document.querySelector('input[name="n88_rfq_nonce"]') 
                        || document.querySelector('input[name="n88-rfq-nonce"]');
                    if (nonceInput) {
                        nonce = nonceInput.value;
                    }
                }
                
                if (nonce) {
                    formData.append('nonce', nonce);
                }
                
                const ajaxUrl = (typeof n88 !== 'undefined' && n88.ajaxUrl) 
                    ? n88.ajaxUrl 
                    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
                
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Project verified:', projectId);
                        resolve();
                    } else {
                        console.error('Project verification failed:', data);
                        reject(new Error(data.data?.message || 'Project not found'));
                    }
                })
                .catch(error => {
                    console.error('Project verification error:', error);
                    reject(error);
                });
            });
        },

        /**
         * Upload PDF for extraction (no project_id required)
         */
        uploadPDFForExtraction: function(file) {
            console.log('Uploading PDF for extraction (no project_id needed)');
            
            const progressDiv = document.getElementById('n88-pdf-upload-progress');
            if (progressDiv) {
                progressDiv.querySelector('.progress-text').textContent = 'Extracting items from PDF...';
            }
            
            // Create FormData - project_id is optional
            const formData = new FormData();
            formData.append('action', 'n88_extract_pdf');
            // Don't require project_id - extraction happens first
            formData.append('pdf_file', file);
            
            // Get nonce from localized script data
            if (typeof n88 !== 'undefined' && n88.nonce) {
                formData.append('nonce', n88.nonce);
            } else {
                // Fallback: try to get from form
                const nonceInput = document.querySelector('input[name="n88_rfq_nonce"]');
                if (nonceInput) {
                    formData.append('nonce', nonceInput.value);
                }
            }
            
            // Get AJAX URL
            const ajaxUrl = (typeof n88 !== 'undefined' && n88.ajaxUrl) 
                ? n88.ajaxUrl 
                : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            // Upload via AJAX
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (progressDiv) {
                    progressDiv.style.display = 'none';
                }
                
                if (data.success) {
                    console.log('=== EXTRACTION SUCCESSFUL ===');
                    console.log('Full response:', JSON.stringify(data.data, null, 2));
                    console.log('Items array:', data.data.items);
                    
                    // Log first item structure
                    if (data.data.items && data.data.items.length > 0) {
                        console.log('First item structure:', JSON.stringify(data.data.items[0], null, 2));
                    }
                    
                    this.currentExtractionData = data.data;
                    
                    // Force call to our showExtractionPreview
                    console.log('Calling showExtractionPreview with data:', data.data);
                    this.showExtractionPreview(data.data);
                } else {
                    alert('Error: ' + (data.data?.message || 'Failed to extract PDF'));
                    console.error('Extraction error:', data);
                }
            })
            .catch(error => {
                console.error('PDF upload error:', error);
                if (progressDiv) {
                    progressDiv.style.display = 'none';
                }
                alert('Error uploading PDF. Please try again.');
            });
        },

        /**
         * Show extraction preview with detected items
         */
        showExtractionPreview: function(extractionData) {
            // UNIQUE IDENTIFIER TO VERIFY THIS FUNCTION IS RUNNING
            console.log('🔵🔵🔵 showExtractionPreview CALLED - VERSION 0.1.1 🔵🔵🔵');
            console.log('Function: N88PDFExtraction.showExtractionPreview');
            console.log('File: n88-rfq-pdf-extraction.js');
            console.log('Timestamp:', new Date().toISOString());
            console.log('Received data:', extractionData);
            
            if (!extractionData || !extractionData.items) {
                console.error('ERROR: Invalid extraction data!', extractionData);
                return;
            }
            
            console.log('Items count:', extractionData.items.length);
            if (extractionData.items.length > 0) {
                console.log('First item from JSON:', JSON.stringify(extractionData.items[0], null, 2));
            }
            
            const previewDiv = document.getElementById('extraction-preview');
            const itemsList = document.getElementById('extraction-items-list');
            const countSpan = document.querySelector('#items-detected-count .count');
            
            if (!previewDiv || !itemsList) {
                console.error('Extraction preview elements not found');
                return;
            }
            
            console.log('Preview elements found, clearing and populating table...');
            
            // Update count in header
            const countElement = document.getElementById('items-detected-count');
            if (countElement) {
                const itemCount = extractionData.items ? extractionData.items.length : (extractionData.items_detected || 0);
                countElement.textContent = itemCount;
            }
            
            // Clear existing items
            itemsList.innerHTML = '';
            
            // Render items - USE EXACT JSON STRUCTURE
            if (extractionData.items && extractionData.items.length > 0) {
                console.log('Processing', extractionData.items.length, 'items from JSON response');
                
                extractionData.items.forEach((item, index) => {
                    console.log(`--- Processing Item ${index} ---`);
                    console.log('Raw item from JSON:', JSON.stringify(item, null, 2));
                    
                    const status = item.status || 'extracted';
                    const statusClass = status === 'extracted' ? 'status-badge-extracted' : 'status-badge-review';
                    const statusText = status === 'extracted' ? '✔ Extracted' : '■ Needs Review';
                    
                    // USE EXACT FIELD NAMES FROM JSON: item.length, item.depth, item.height
                    // DO NOT use item.dimensions or formatDimensions - use direct values
                    let length = item.length;  // Direct from JSON
                    let depth = item.depth;    // Direct from JSON
                    let height = item.height;  // Direct from JSON
                    
                    console.log('Item', index, 'dimensions from JSON:');
                    console.log('  length:', length, '(type:', typeof length + ')');
                    console.log('  depth:', depth, '(type:', typeof depth + ')');
                    console.log('  height:', height, '(type:', typeof height + ')');
                    
                    // Convert to numbers if needed
                    if (typeof length === 'string') {
                        length = parseFloat(length) || 0;
                    }
                    if (typeof depth === 'string') {
                        depth = parseFloat(depth) || 0;
                    }
                    if (typeof height === 'string') {
                        height = parseFloat(height) || 0;
                    }
                    
                    length = typeof length === 'number' ? length : 0;
                    depth = typeof depth === 'number' ? depth : 0;
                    height = typeof height === 'number' ? height : 0;
                    
                    // If any dimension contains a combined format string like "24.5" × 26" × 42"", extract individual numbers
                    if (typeof length === 'string' && (length.includes('×') || length.includes('x'))) {
                        console.log('N88 RFQ: Found combined format in length:', length);
                        const dimParts = length.split(/["×x]/).map(p => parseFloat(p.trim())).filter(n => !isNaN(n));
                        if (dimParts.length >= 3) {
                            length = dimParts[0];
                            depth = dimParts[1];
                            height = dimParts[2];
                            console.log('N88 RFQ: Extracted from combined format - length:', length, 'depth:', depth, 'height:', height);
                        } else if (dimParts.length > 0) {
                            length = dimParts[0];
                        }
                    } else {
                        // Convert to numbers if they're strings
                        length = typeof length === 'string' ? parseFloat(length) || 0 : (typeof length === 'number' ? length : 0);
                    }
                    
                    if (typeof depth === 'string' && (depth.includes('×') || depth.includes('x'))) {
                        console.log('N88 RFQ: Found combined format in depth:', depth);
                        const dimParts = depth.split(/["×x]/).map(p => parseFloat(p.trim())).filter(n => !isNaN(n));
                        if (dimParts.length >= 2) {
                            depth = dimParts[0];
                            height = dimParts[1];
                        } else if (dimParts.length > 0) {
                            depth = dimParts[0];
                        }
                    } else {
                        depth = typeof depth === 'string' ? parseFloat(depth) || 0 : (typeof depth === 'number' ? depth : 0);
                    }
                    
                    if (typeof height === 'string' && (height.includes('×') || height.includes('x'))) {
                        console.log('N88 RFQ: Found combined format in height:', height);
                        const dimParts = height.split(/["×x]/).map(p => parseFloat(p.trim())).filter(n => !isNaN(n));
                        if (dimParts.length > 0) {
                            height = dimParts[0];
                        }
                    } else {
                        height = typeof height === 'string' ? parseFloat(height) || 0 : (typeof height === 'number' ? height : 0);
                    }
                    
                    // Debug: Log final dimension values
                    console.log('N88 RFQ: Item', index, 'final dimensions - length:', length, 'depth:', depth, 'height:', height);
                    
                    // USE EXACT FIELD NAMES FROM JSON RESPONSE
                    const primaryMaterial = item.primary_material || 'N/A';
                    const finish = item.finishes || 'N/A';
                    const constructionNotes = item.construction_notes || 'N/A';
                    const quantity = item.quantity || 1;
                    
                    console.log('Item', index, 'other fields from JSON:');
                    console.log('  quantity:', quantity);
                    console.log('  primary_material:', primaryMaterial);
                    console.log('  finishes:', finish);
                    console.log('  construction_notes:', constructionNotes);
                    
                    // CRITICAL: Use EXACT values from JSON - NO FORMATTING, NO COMBINING
                    // Each dimension must be displayed separately as a number only
                    let displayLength = (length && length > 0) ? String(length) : 'N/A';
                    let displayDepth = (depth && depth > 0) ? String(depth) : 'N/A';
                    let displayHeight = (height && height > 0) ? String(height) : 'N/A';
                    
                    // SAFETY CHECK: If somehow a combined format got in, extract just the number
                    if (displayLength.includes('×') || displayLength.includes('x')) {
                        console.error('ERROR: displayLength contains ×! Extracting number only.');
                        const numMatch = displayLength.match(/(\d+\.?\d*)/);
                        if (numMatch) displayLength = numMatch[1];
                    }
                    if (displayDepth.includes('×') || displayDepth.includes('x')) {
                        console.error('ERROR: displayDepth contains ×! Extracting number only.');
                        const numMatch = displayDepth.match(/(\d+\.?\d*)/);
                        if (numMatch) displayDepth = numMatch[1];
                    }
                    if (displayHeight.includes('×') || displayHeight.includes('x')) {
                        console.error('ERROR: displayHeight contains ×! Extracting number only.');
                        const numMatch = displayHeight.match(/(\d+\.?\d*)/);
                        if (numMatch) displayHeight = numMatch[1];
                    }
                    
                    console.log('=== FINAL VALUES FOR TABLE (Item', index, ') ===');
                    console.log('Length:', displayLength, '- MUST BE NUMBER ONLY (e.g., "24.5")');
                    console.log('Depth:', displayDepth, '- MUST BE NUMBER ONLY (e.g., "26")');
                    console.log('Height:', displayHeight, '- MUST BE NUMBER ONLY (e.g., "42")');
                    console.log('Quantity:', quantity);
                    console.log('Material:', primaryMaterial);
                    console.log('Finish:', finish);
                    console.log('Notes:', constructionNotes);
                    
                    // Create row with new structure: Thumbnail, Item Title, Dimensions, Materials, Quantity, Status
                    const row = document.createElement('tr');
                    
                    // Cell 1: Thumbnail (placeholder icon)
                    const cell1 = document.createElement('td');
                    cell1.innerHTML = '<div class="extraction-thumbnail">📄</div>';
                    row.appendChild(cell1);
                    
                    // Cell 2: Item Title
                    const cell2 = document.createElement('td');
                    const itemTitle = item.title || 'Item ' + (index + 1);
                    cell2.innerHTML = `<strong>${this.escapeHtml(itemTitle)}</strong>`;
                    row.appendChild(cell2);
                    
                    // Cell 3: Dimensions (combined display)
                    const cell3 = document.createElement('td');
                    const dimsText = `${displayLength}" × ${displayDepth}" × ${displayHeight}"`;
                    cell3.textContent = dimsText;
                    row.appendChild(cell3);
                    
                    // Cell 4: Materials
                    const cell4 = document.createElement('td');
                    cell4.textContent = primaryMaterial;
                    row.appendChild(cell4);
                    
                    // Cell 5: Quantity
                    const cell5 = document.createElement('td');
                    cell5.textContent = String(quantity);
                    row.appendChild(cell5);
                    
                    // Cell 6: Status
                    const cell6 = document.createElement('td');
                    cell6.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
                    row.appendChild(cell6);
                    
                    itemsList.appendChild(row);
                    console.log('✓ Item', index, 'COMPLETE - Row has', row.cells.length, 'cells');
                    console.log('Row HTML preview:', row.innerHTML.substring(0, 300));
                });
            }
            
            // Show preview
            previewDiv.style.display = 'block';
        },

        /**
         * Format dimensions from item data
         */
        formatDimensions: function(item) {
            if (item.dimensions) {
                return `${item.dimensions.length || 0}" × ${item.dimensions.depth || 0}" × ${item.dimensions.height || 0}"`;
            } else if (item.length && item.depth && item.height) {
                return `${item.length}" × ${item.depth}" × ${item.height}"`;
            }
            return 'N/A';
        },

        /**
         * Format materials from item data
         */
        formatMaterials: function(item) {
            if (Array.isArray(item.materials)) {
                return item.materials.join(', ');
            } else if (item.materials) {
                return item.materials;
            } else if (item.primary_material) {
                return item.primary_material;
            }
            return 'N/A';
        },

        /**
         * Setup extraction confirmation handlers
         */
        setupExtractionHandlers: function() {
            // Confirm extraction button
            const confirmBtn = document.getElementById('confirm-extraction-btn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.confirmExtraction();
                });
            }
            
            // Cancel extraction button
            const cancelBtn = document.getElementById('cancel-extraction-btn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.cancelExtraction();
                });
            }
        },

        /**
         * Confirm extraction and import items directly into form
         */
        confirmExtraction: function() {
            console.log('=== confirmExtraction CALLED ===');
            
            if (!this.currentExtractionData || !this.currentExtractionData.items || this.currentExtractionData.items.length === 0) {
                alert('No items to import.');
                return;
            }
            
            console.log('Importing', this.currentExtractionData.items.length, 'items into form');
            
            // Get the pieces container (manual mode items section)
            const piecesContainer = document.getElementById('pieces-container');
            if (!piecesContainer) {
                console.error('Pieces container not found');
                alert('Could not find items section. Please refresh the page and try again.');
                return;
            }
            
            // Get existing items
            const existingItems = piecesContainer.querySelectorAll('.piece-item');
            
            // Check if first item is empty (has no data filled in)
            const isFirstItemEmpty = (item) => {
                if (!item) return false;
                const length = item.querySelector('input[name*="[length_in]"]')?.value?.trim() || '';
                const depth = item.querySelector('input[name*="[depth_in]"]')?.value?.trim() || '';
                const height = item.querySelector('input[name*="[height_in]"]')?.value?.trim() || '';
                const material = item.querySelector('input[name*="[primary_material]"]')?.value?.trim() || '';
                return !length && !depth && !height && !material;
            };
            
            // Remove empty items before importing (so imported items start from Item 1)
            let removedCount = 0;
            existingItems.forEach((item) => {
                if (isFirstItemEmpty(item)) {
                    item.remove();
                    removedCount++;
                }
            });
            
            // Recalculate item count after removing empty items
            const remainingItems = piecesContainer.querySelectorAll('.piece-item');
            let itemCount = remainingItems.length;
            
            console.log('Removed', removedCount, 'empty item(s). Starting import at item', itemCount + 1);
            
            // Function to add remove listener
            const attachRemoveListener = (btn) => {
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.closest('.piece-item').remove();
                    });
                }
            };
            
            // Import each extracted item into the form
            this.currentExtractionData.items.forEach((item, index) => {
                console.log('Importing item', index, ':', item);
                
                const newItem = document.createElement('div');
                newItem.className = 'piece-item n88-item-extracted';
                
                // Get values from extracted item
                const length = item.length || '';
                const depth = item.depth || '';
                const height = item.height || '';
                const quantity = item.quantity || 1;
                const primaryMaterial = item.primary_material || '';
                const finishes = item.finishes || '';
                const constructionNotes = item.construction_notes || '';
                const notes = item.notes || '';
                const cushions = item.cushions || '';
                const fabricCategory = item.fabric_category || '';
                const frameMaterial = item.frame_material || '';
                const finish = item.finish || '';
                
                // Check if item needs review
                const needsReview = item.status === 'needs_review';
                if (needsReview) {
                    newItem.classList.add('n88-item-needs-review');
                }
                
                // Determine if fields should be locked (readonly only - NOT disabled, because disabled fields don't submit)
                const isLocked = !needsReview;
                const lockAttr = isLocked ? 'readonly' : ''; // Removed 'disabled' - readonly fields ARE submitted, disabled fields are NOT
                const lockClass = isLocked ? 'n88-field-locked' : '';
                
                // Create item HTML with extracted data - matching exact PHP structure
                newItem.innerHTML = `
                    <div class="piece-item-header">
                        <h4>
                            Item ${itemCount + 1}
                            ${needsReview ? '<span class="n88-extraction-badge n88-badge-review">■ Needs Review</span>' : '<span class="n88-extraction-badge n88-badge-extracted">✔ Extracted</span>'}
                        </h4>
                        <button type="button" class="btn btn-remove">Remove</button>
                    </div>
                    <div class="piece-item-fields">
                        <!-- 1. Primary Material -->
                        <div class="form-group">
                            <label>Primary Material <span class="required">*</span></label>
                            <input type="text" name="pieces[${itemCount}][primary_material]" value="${this.escapeHtml(primaryMaterial)}" ${lockAttr} class="${lockClass} n88-item-field" required>
                        </div>
                        
                        <!-- 2. Dimensions (Length, Depth, Height, Quantity in same row) -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Length (in) <span class="required">*</span></label>
                                <input type="number" step="0.01" name="pieces[${itemCount}][length_in]" value="${length}" ${lockAttr} class="${lockClass} n88-item-field" required>
                                ${isLocked ? '<small class="n88-locked-hint">Locked (extracted from PDF)</small>' : ''}
                            </div>
                            <div class="form-group">
                                <label>Depth (in) <span class="required">*</span></label>
                                <input type="number" step="0.01" name="pieces[${itemCount}][depth_in]" value="${depth}" ${lockAttr} class="${lockClass} n88-item-field" required>
                            </div>
                            <div class="form-group">
                                <label>Height (in) <span class="required">*</span></label>
                                <input type="number" step="0.01" name="pieces[${itemCount}][height_in]" value="${height}" ${lockAttr} class="${lockClass} n88-item-field" required>
                            </div>
                            <div class="form-group">
                                <label>Quantity <span class="required">*</span></label>
                                <input type="number" name="pieces[${itemCount}][quantity]" value="${quantity}" ${lockAttr} class="${lockClass} n88-item-field" required>
                            </div>
                        </div>
                        
                        <!-- 3. Construction Notes -->
                        <div class="form-group">
                            <label>Construction Notes <span class="required">*</span></label>
                            <textarea name="pieces[${itemCount}][construction_notes]" rows="3" ${lockAttr} class="${lockClass} n88-item-field" required>${this.escapeHtml(constructionNotes)}</textarea>
                        </div>
                        
                        <!-- 4. Finishes -->
                        <div class="form-group">
                            <label>Finishes <span class="required">*</span></label>
                            <input type="text" name="pieces[${itemCount}][finishes]" value="${this.escapeHtml(finishes)}" ${lockAttr} class="${lockClass} n88-item-field" required>
                        </div>
                        
                        <!-- 5. Files (hidden for extracted items) -->
                        <div class="form-group n88-item-files-section n88-manual-entry-only" style="display: none;">
                            <label>Files</label>
                            <div class="n88-item-file-upload-wrapper">
                                <div class="n88-item-file-uploader" id="item-file-uploader-${itemCount}" 
                                     ondrop="event.preventDefault(); handleItemFileDrop(event, ${itemCount})" 
                                     ondragover="event.preventDefault(); this.style.background='#f0f0f0';" 
                                     ondragleave="this.style.background='';">
                                    <input type="file" 
                                           id="item-file-input-${itemCount}" 
                                           name="item_files[${itemCount}][]" 
                                           multiple 
                                           accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg" 
                                           style="display: none;"
                                           onchange="handleItemFileSelect(event, ${itemCount})">
                                    <div class="n88-item-file-upload-content" onclick="document.getElementById('item-file-input-${itemCount}').click();">
                                        <svg class="n88-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width: 24px; height: 24px;">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                        <p style="margin: 8px 0 4px 0; font-size: 14px; color: #666;">Drag files here or click to browse</p>
                                        <small style="color: #999; font-size: 12px;">PDF, JPG, PNG, GIF, DWG</small>
                                    </div>
                                </div>
                                <div id="item-files-list-${itemCount}" class="n88-item-files-list">
                                    <!-- Uploaded files will appear here -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- 6. Notes -->
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="pieces[${itemCount}][notes]" rows="3" ${lockAttr} class="${lockClass} n88-item-field">${this.escapeHtml(notes)}</textarea>
                        </div>
                        
                        <!-- 7. Additional optional fields -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Cushions</label>
                                <input type="number" name="pieces[${itemCount}][cushions]" value="${this.escapeHtml(cushions)}" ${lockAttr} class="${lockClass} n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Fabric Category</label>
                                <input type="text" name="pieces[${itemCount}][fabric_category]" value="${this.escapeHtml(fabricCategory)}" ${lockAttr} class="${lockClass} n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Frame Material</label>
                                <input type="text" name="pieces[${itemCount}][frame_material]" value="${this.escapeHtml(frameMaterial)}" ${lockAttr} class="${lockClass} n88-item-field">
                            </div>
                            <div class="form-group">
                                <label>Finish</label>
                                <input type="text" name="pieces[${itemCount}][finish]" value="${this.escapeHtml(finish)}" ${lockAttr} class="${lockClass} n88-item-field">
                            </div>
                        </div>
                        
                        <input type="hidden" name="pieces[${itemCount}][extracted]" value="1">
                        <input type="hidden" name="pieces[${itemCount}][extraction_status]" value="${item.status || 'extracted'}">
                        <input type="hidden" name="pieces[${itemCount}][locked]" value="${needsReview ? '0' : '1'}">
                        <!-- Store original values for change tracking -->
                        <input type="hidden" name="pieces[${itemCount}][original_length]" value="${length}">
                        <input type="hidden" name="pieces[${itemCount}][original_depth]" value="${depth}">
                        <input type="hidden" name="pieces[${itemCount}][original_height]" value="${height}">
                        <input type="hidden" name="pieces[${itemCount}][original_material]" value="${this.escapeHtml(primaryMaterial)}">
                        <input type="hidden" name="pieces[${itemCount}][original_finishes]" value="${this.escapeHtml(finishes)}">
                        <input type="hidden" name="pieces[${itemCount}][original_quantity]" value="${quantity}">
                        <input type="hidden" name="pieces[${itemCount}][original_notes]" value="${this.escapeHtml(constructionNotes)}">
                    </div>
                `;
                
                piecesContainer.appendChild(newItem);
                attachRemoveListener(newItem.querySelector('.btn-remove'));
                itemCount++;
                
                console.log('Item', index, 'imported as item', itemCount);
            });
            
            // Hide extraction preview
            const previewDiv = document.getElementById('extraction-preview');
            if (previewDiv) {
                previewDiv.style.display = 'none';
            }
            
            // Switch back to manual mode to show the imported items
            const manualRadio = document.querySelector('input[name="entry_mode"][value="manual"]');
            if (manualRadio) {
                manualRadio.checked = true;
                if (typeof window.n88ToggleEntryMode === 'function') {
                    window.n88ToggleEntryMode('manual');
                } else if (typeof this.toggleEntryMode === 'function') {
                    this.toggleEntryMode('manual');
                }
            }
            
            // Scroll to items section
            const itemsSection = document.getElementById('pieces-container');
            if (itemsSection) {
                itemsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            
            // Show success message
            const itemsCount = this.currentExtractionData.items.length;
            console.log('✓ All', itemsCount, 'items imported successfully into form');
            
            // Clear extraction data
            this.currentExtractionData = null;
            
            // Show user-friendly message
            const successNotice = document.createElement('div');
            successNotice.className = 'n88-extraction-success';
            successNotice.textContent = `${itemsCount} extracted item(s) imported. Review below, then finish the form.`;
            const pdfSection = document.getElementById('pdf-upload-mode') || document.querySelector('.n88-pdf-upload-section');
            if (pdfSection) {
                pdfSection.parentNode.insertBefore(successNotice, pdfSection);
                setTimeout(() => {
                    successNotice.remove();
                }, 5000);
            }
        },

        /**
         * Cancel extraction and reset
         */
        cancelExtraction: function() {
            const previewDiv = document.getElementById('extraction-preview');
            if (previewDiv) {
                previewDiv.style.display = 'none';
            }
            this.currentExtractionData = null;
            const pdfInput = document.getElementById('pdf_file_upload');
            if (pdfInput) {
                pdfInput.value = '';
            }
        },


        /**
         * Get project ID from form or URL
         */
        getProjectId: function() {
            // Try to get from hidden input (prefer ID selector)
            const hiddenInput = document.getElementById('n88-project-id-input') || document.querySelector('input[name="project_id"]');
            if (hiddenInput && hiddenInput.value) {
                return hiddenInput.value;
            }
            
            // Try to get from URL
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('project_id') || null;
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            N88PDFExtraction.init();
        });
    } else {
        N88PDFExtraction.init();
    }
    
    // Also try on window load as fallback
    window.addEventListener('load', () => {
        if (!N88PDFExtraction.initialized) {
            N88PDFExtraction.init();
        }
    });

    // Make globally accessible
    window.N88PDFExtraction = N88PDFExtraction;
    window.n88ToggleEntryMode = function(mode) {
        N88PDFExtraction.toggleEntryMode(mode);
    };

})();


