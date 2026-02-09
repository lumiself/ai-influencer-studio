(function($, wp) {
    'use strict';
    
    const { createElement: el, useState, useEffect, useRef, Fragment } = wp.element;
    const { Button, SelectControl, Spinner, Notice, TextareaControl, TabPanel } = wp.components;
    
    // Polling helper function
    const pollPrediction = (predictionId, onSuccess, onError, onProgress) => {
        const pollInterval = aisData.pollInterval || 3000;
        let attempts = 0;
        const maxAttempts = 120; // 6 minutes max
        
        const poll = async () => {
            attempts++;
            
            if (attempts > maxAttempts) {
                onError('Generation timed out. Please try again.');
                return;
            }
            
            try {
                const response = await $.ajax({
                    url: aisData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_poll_prediction',
                        nonce: aisData.nonce,
                        prediction_id: predictionId
                    }
                });
                
                if (response.success) {
                    if (response.data.status === 'succeeded' && response.data.image_url) {
                        onSuccess(response.data.image_url);
                    } else if (response.data.status === 'failed' || response.data.status === 'canceled') {
                        onError(response.data.message || 'Generation failed.');
                    } else {
                        // Still processing
                        onProgress(response.data.status, attempts);
                        setTimeout(poll, pollInterval);
                    }
                } else {
                    onError(response.data.message || 'Failed to check status.');
                }
            } catch (err) {
                onError('Network error. Please try again.');
            }
        };
        
        poll();
    };
    
    // Image Upload Component
    function ImageUpload({ label, value, onChange, id }) {
        const [preview, setPreview] = useState('');
        
        useEffect(() => {
            if (value) {
                // Get attachment URL
                wp.media.attachment(value).fetch().then(function(attachment) {
                    setPreview(attachment.url);
                });
            } else {
                setPreview('');
            }
        }, [value]);
        
        const openMediaLibrary = () => {
            const frame = wp.media({
                title: label,
                multiple: false,
                library: { type: 'image' }
            });
            
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                onChange(attachment.id);
                setPreview(attachment.url);
            });
            
            frame.open();
        };
        
        return el('div', { className: 'ais-image-upload' },
            el('label', { className: 'ais-image-label' }, label),
            el('div', { className: 'ais-image-preview', onClick: openMediaLibrary },
                preview 
                    ? el('img', { src: preview, alt: label })
                    : el('div', { className: 'ais-image-placeholder' },
                        el('span', { className: 'dashicons dashicons-plus-alt2' }),
                        el('span', null, 'Click to upload')
                    )
            ),
            value && el(Button, { 
                isDestructive: true, 
                isSmall: true,
                onClick: (e) => { e.stopPropagation(); onChange(null); setPreview(''); }
            }, 'Remove')
        );
    }
    
    // Pose Selection Component
    function PoseSelector({ poses, selectedPose, onSelect, onEdit, editedPose }) {
        return el('div', { className: 'ais-pose-selector' },
            el('h3', null, 'Select a Pose'),
            el('div', { className: 'ais-pose-list' },
                poses.map((pose, index) => 
                    el('div', { 
                        key: index,
                        className: 'ais-pose-item' + (selectedPose === index ? ' selected' : ''),
                        onClick: () => onSelect(index)
                    },
                        el('span', { className: 'ais-pose-number' }, index + 1),
                        el('p', null, pose)
                    )
                )
            ),
            selectedPose !== null && el('div', { className: 'ais-pose-edit' },
                el(TextareaControl, {
                    label: 'Edit Pose (optional)',
                    value: editedPose || poses[selectedPose],
                    onChange: onEdit,
                    rows: 3
                })
            )
        );
    }
    
    // Result Display Component
    function ResultDisplay({ imageUrl, onSave, saving }) {
        return el('div', { className: 'ais-result' },
            el('h3', null, 'Generated Image'),
            el('div', { className: 'ais-result-image' },
                el('img', { src: imageUrl, alt: 'Generated result' })
            ),
            el('div', { className: 'ais-result-actions' },
                el(Button, { 
                    isPrimary: true, 
                    onClick: onSave,
                    disabled: saving
                }, saving ? el(Spinner) : 'Save to Media Library'),
                el(Button, {
                    isSecondary: true,
                    href: imageUrl,
                    target: '_blank'
                }, 'Open Full Size')
            )
        );
    }
    
    // Single Model Form
    function SingleModelForm() {
        const [identity, setIdentity] = useState(null);
        const [outfit, setOutfit] = useState(null);
        const [background, setBackground] = useState(null);
        const [gender, setGender] = useState('female');
        const [posePreset, setPosePreset] = useState('casual');
        const [poses, setPoses] = useState([]);
        const [selectedPose, setSelectedPose] = useState(null);
        const [editedPose, setEditedPose] = useState('');
        const [loading, setLoading] = useState(false);
        const [generating, setGenerating] = useState(false);
        const [saving, setSaving] = useState(false);
        const [resultImage, setResultImage] = useState('');
        const [error, setError] = useState('');
        const [success, setSuccess] = useState('');
        
        const generatePoses = async () => {
            if (!background) {
                setError('Please upload a background image first.');
                return;
            }
            
            setLoading(true);
            setError('');
            setPoses([]);
            setSelectedPose(null);
            
            try {
                const response = await $.ajax({
                    url: aisData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_generate_poses',
                        nonce: aisData.nonce,
                        background_id: background,
                        outfit_id: outfit,
                        gender: gender,
                        pose_preset: posePreset
                    }
                });
                
                if (response.success) {
                    setPoses(response.data.poses);
                } else {
                    setError(response.data.message || 'Failed to generate poses.');
                }
            } catch (err) {
                setError('Network error. Please try again.');
            }
            
            setLoading(false);
        };
        
        const synthesizeImage = async () => {
            if (!identity || !outfit || !background || selectedPose === null) {
                setError('Please complete all fields and select a pose.');
                return;
            }
            
            setGenerating(true);
            setError('');
            setResultImage('');
            
            const posePrompt = editedPose || poses[selectedPose];
            
            try {
                // Use async mode for shared hosting compatibility
                if (aisData.asyncMode) {
                    const response = await $.ajax({
                        url: aisData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_image_async',
                            nonce: aisData.nonce,
                            identity_id: identity,
                            outfit_id: outfit,
                            background_id: background,
                            pose_prompt: posePrompt
                        }
                    });
                    
                    if (response.success && response.data.prediction_id) {
                        pollPrediction(
                            response.data.prediction_id,
                            (imageUrl) => {
                                setResultImage(imageUrl);
                                setGenerating(false);
                            },
                            (errorMsg) => {
                                setError(errorMsg);
                                setGenerating(false);
                            },
                            (status, attempts) => {
                                // Status update (no-op without progress display)
                            }
                        );
                    } else {
                        setError(response.data?.message || 'Failed to start generation.');
                        setGenerating(false);
                    }
                } else {
                    // Sync mode (for powerful servers)
                    const response = await $.ajax({
                        url: aisData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_image',
                            nonce: aisData.nonce,
                            identity_id: identity,
                            outfit_id: outfit,
                            background_id: background,
                            pose_prompt: posePrompt
                        }
                    });
                    
                    if (response.success) {
                        setResultImage(response.data.image_url);
                    } else {
                        setError(response.data.message || 'Failed to generate image.');
                    }
                    setGenerating(false);
                }
            } catch (err) {
                setError('Network error. Please try again.');
                setGenerating(false);
            }
        };
        
        const saveToMedia = async () => {
            setSaving(true);
            setError('');
            
            try {
                const response = await $.ajax({
                    url: aisData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_save_to_media',
                        nonce: aisData.nonce,
                        image_url: resultImage
                    }
                });
                
                if (response.success) {
                    setSuccess('Image saved to Media Library!');
                } else {
                    setError(response.data.message || 'Failed to save image.');
                }
            } catch (err) {
                setError('Network error. Please try again.');
            }
            
            setSaving(false);
        };
        
        return el('div', { className: 'ais-single-model-form' },
            error && el(Notice, { status: 'error', isDismissible: true, onRemove: () => setError('') }, error),
            success && el(Notice, { status: 'success', isDismissible: true, onRemove: () => setSuccess('') }, success),
            
            el('div', { className: 'ais-form-section' },
                el('h3', null, 'Step 1: Upload Images'),
                el('div', { className: 'ais-image-grid' },
                    el(ImageUpload, { label: 'Identity Photo', value: identity, onChange: setIdentity }),
                    el(ImageUpload, { label: 'Outfit Photo', value: outfit, onChange: setOutfit }),
                    el(ImageUpload, { label: 'Background', value: background, onChange: setBackground })
                )
            ),
            
            el('div', { className: 'ais-form-section' },
                el('h3', null, 'Step 2: Configure Settings'),
                el('div', { className: 'ais-settings-grid' },
                    el(SelectControl, {
                        label: 'Gender',
                        value: gender,
                        options: Object.entries(aisData.genderOptions).map(([val, label]) => ({ value: val, label: label })),
                        onChange: setGender
                    }),
                    el(SelectControl, {
                        label: 'Pose Preset',
                        value: posePreset,
                        options: Object.entries(aisData.posePresets).map(([val, label]) => ({ value: val, label: label })),
                        onChange: setPosePreset
                    })
                ),
                el(Button, { 
                    isPrimary: true, 
                    onClick: generatePoses,
                    disabled: loading || !background
                }, loading ? el(Fragment, null, el(Spinner), ' Analyzing background...') : 'Generate Pose Suggestions')
            ),
            
            poses.length > 0 && el('div', { className: 'ais-form-section' },
                el(PoseSelector, {
                    poses: poses,
                    selectedPose: selectedPose,
                    onSelect: (idx) => { setSelectedPose(idx); setEditedPose(''); },
                    onEdit: setEditedPose,
                    editedPose: editedPose
                }),
                el(Button, {
                    isPrimary: true,
                    onClick: synthesizeImage,
                    disabled: generating || selectedPose === null || !identity || !outfit
                }, generating ? el(Fragment, null, el(Spinner), ' Generating image...') : 'Generate Final Image')
            ),
            
            resultImage && el('div', { className: 'ais-form-section' },
                el(ResultDisplay, {
                    imageUrl: resultImage,
                    onSave: saveToMedia,
                    saving: saving
                })
            )
        );
    }
    
    // Dual Model Form
    function DualModelForm() {
        const [identity1, setIdentity1] = useState(null);
        const [outfit1, setOutfit1] = useState(null);
        const [gender1, setGender1] = useState('female');
        const [identity2, setIdentity2] = useState(null);
        const [outfit2, setOutfit2] = useState(null);
        const [gender2, setGender2] = useState('male');
        const [background, setBackground] = useState(null);
        const [posePreset, setPosePreset] = useState('casual');
        const [poses, setPoses] = useState([]);
        const [selectedPose, setSelectedPose] = useState(null);
        const [editedPose, setEditedPose] = useState('');
        const [loading, setLoading] = useState(false);
        const [generating, setGenerating] = useState(false);
        const [saving, setSaving] = useState(false);
        const [resultImage, setResultImage] = useState('');
        const [error, setError] = useState('');
        const [success, setSuccess] = useState('');
        
        const generatePoses = async () => {
            if (!background) {
                setError('Please upload a background image first.');
                return;
            }
            
            setLoading(true);
            setError('');
            setPoses([]);
            setSelectedPose(null);
            
            try {
                const response = await $.ajax({
                    url: aisData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_generate_dual_poses',
                        nonce: aisData.nonce,
                        background_id: background,
                        outfit1_id: outfit1,
                        outfit2_id: outfit2,
                        gender_a: gender1,
                        gender_b: gender2,
                        pose_preset: posePreset
                    }
                });
                
                if (response.success) {
                    setPoses(response.data.poses);
                } else {
                    setError(response.data.message || 'Failed to generate poses.');
                }
            } catch (err) {
                setError('Network error. Please try again.');
            }
            
            setLoading(false);
        };
        
        const synthesizeImage = async () => {
            if (!identity1 || !outfit1 || !identity2 || !outfit2 || !background || selectedPose === null) {
                setError('Please complete all fields and select a pose.');
                return;
            }
            
            setGenerating(true);
            setError('');
            setResultImage('');
            
            const posePrompt = editedPose || poses[selectedPose];
            
            try {
                // Use async mode for shared hosting compatibility
                if (aisData.asyncMode) {
                    const response = await $.ajax({
                        url: aisData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_dual_image_async',
                            nonce: aisData.nonce,
                            identity1_id: identity1,
                            outfit1_id: outfit1,
                            identity2_id: identity2,
                            outfit2_id: outfit2,
                            background_id: background,
                            pose_prompt: posePrompt
                        }
                    });
                    
                    if (response.success && response.data.prediction_id) {
                        pollPrediction(
                            response.data.prediction_id,
                            (imageUrl) => {
                                setResultImage(imageUrl);
                                setGenerating(false);
                            },
                            (errorMsg) => {
                                setError(errorMsg);
                                setGenerating(false);
                            },
                            (status, attempts) => {
                                // Status update (no-op)
                            }
                        );
                    } else {
                        setError(response.data?.message || 'Failed to start generation.');
                        setGenerating(false);
                    }
                } else {
                    // Sync mode (for powerful servers)
                    const response = await $.ajax({
                        url: aisData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_dual_image',
                            nonce: aisData.nonce,
                            identity1_id: identity1,
                            outfit1_id: outfit1,
                            identity2_id: identity2,
                            outfit2_id: outfit2,
                            background_id: background,
                            pose_prompt: posePrompt
                        }
                    });
                    
                    if (response.success) {
                        setResultImage(response.data.image_url);
                    } else {
                        setError(response.data.message || 'Failed to generate image.');
                    }
                    setGenerating(false);
                }
            } catch (err) {
                setError('Network error. Please try again.');
                setGenerating(false);
            }
        };
        
        const saveToMedia = async () => {
            setSaving(true);
            setError('');
            
            try {
                const response = await $.ajax({
                    url: aisData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_save_to_media',
                        nonce: aisData.nonce,
                        image_url: resultImage
                    }
                });
                
                if (response.success) {
                    setSuccess('Image saved to Media Library!');
                } else {
                    setError(response.data.message || 'Failed to save image.');
                }
            } catch (err) {
                setError('Network error. Please try again.');
            }
            
            setSaving(false);
        };
        
        return el('div', { className: 'ais-dual-model-form' },
            error && el(Notice, { status: 'error', isDismissible: true, onRemove: () => setError('') }, error),
            success && el(Notice, { status: 'success', isDismissible: true, onRemove: () => setSuccess('') }, success),
            
            el('div', { className: 'ais-form-section' },
                el('h3', null, 'Model A'),
                el('div', { className: 'ais-image-grid ais-grid-2' },
                    el(ImageUpload, { label: 'Identity Photo', value: identity1, onChange: setIdentity1 }),
                    el(ImageUpload, { label: 'Outfit Photo', value: outfit1, onChange: setOutfit1 })
                ),
                el(SelectControl, {
                    label: 'Gender',
                    value: gender1,
                    options: Object.entries(aisData.genderOptions).map(([val, label]) => ({ value: val, label: label })),
                    onChange: setGender1
                })
            ),
            
            el('div', { className: 'ais-form-section' },
                el('h3', null, 'Model B'),
                el('div', { className: 'ais-image-grid ais-grid-2' },
                    el(ImageUpload, { label: 'Identity Photo', value: identity2, onChange: setIdentity2 }),
                    el(ImageUpload, { label: 'Outfit Photo', value: outfit2, onChange: setOutfit2 })
                ),
                el(SelectControl, {
                    label: 'Gender',
                    value: gender2,
                    options: Object.entries(aisData.genderOptions).map(([val, label]) => ({ value: val, label: label })),
                    onChange: setGender2
                })
            ),
            
            el('div', { className: 'ais-form-section' },
                el('h3', null, 'Background & Settings'),
                el('div', { className: 'ais-image-grid ais-grid-1' },
                    el(ImageUpload, { label: 'Background', value: background, onChange: setBackground })
                ),
                el(SelectControl, {
                    label: 'Pose Preset',
                    value: posePreset,
                    options: Object.entries(aisData.posePresets).map(([val, label]) => ({ value: val, label: label })),
                    onChange: setPosePreset
                }),
                el(Button, { 
                    isPrimary: true, 
                    onClick: generatePoses,
                    disabled: loading || !background
                }, loading ? el(Fragment, null, el(Spinner), ' Analyzing background...') : 'Generate Duo Pose Suggestions')
            ),
            
            poses.length > 0 && el('div', { className: 'ais-form-section' },
                el(PoseSelector, {
                    poses: poses,
                    selectedPose: selectedPose,
                    onSelect: (idx) => { setSelectedPose(idx); setEditedPose(''); },
                    onEdit: setEditedPose,
                    editedPose: editedPose
                }),
                el(Button, {
                    isPrimary: true,
                    onClick: synthesizeImage,
                    disabled: generating || selectedPose === null || !identity1 || !outfit1 || !identity2 || !outfit2
                }, generating ? el(Fragment, null, el(Spinner), ' Generating image...') : 'Generate Final Image')
            ),
            
            resultImage && el('div', { className: 'ais-form-section' },
                el(ResultDisplay, {
                    imageUrl: resultImage,
                    onSave: saveToMedia,
                    saving: saving
                })
            )
        );
    }
    
    // Main App Component
    function App() {
        return el('div', { className: 'ais-app' },
            el(TabPanel, {
                className: 'ais-tabs',
                activeClass: 'is-active',
                tabs: [
                    { name: 'single', title: 'Single Model', className: 'ais-tab' },
                    { name: 'dual', title: 'Dual Models', className: 'ais-tab' }
                ]
            }, (tab) => {
                if (tab.name === 'single') {
                    return el(SingleModelForm);
                }
                return el(DualModelForm);
            })
        );
    }
    
    // Mount the app
    document.addEventListener('DOMContentLoaded', function() {
        const root = document.getElementById('ais-app-root');
        if (root) {
            wp.element.render(el(App), root);
        }
    });
    
})(jQuery, wp);
