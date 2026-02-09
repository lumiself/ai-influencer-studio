(function($, wp) {
    'use strict';
    
    const { createElement: el, useState, useEffect, Fragment } = wp.element;
    const data = window.aisFrontendData || {};
    const i18n = data.i18n || {};
    
    // SVG Icons
    const icons = {
        plus: el('svg', { className: 'ais-upload-icon', viewBox: '0 0 24 24', fill: 'none', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' },
            el('line', { x1: '12', y1: '5', x2: '12', y2: '19' }),
            el('line', { x1: '5', y1: '12', x2: '19', y2: '12' })
        ),
        x: el('svg', { viewBox: '0 0 24 24', fill: 'none', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' },
            el('line', { x1: '18', y1: '6', x2: '6', y2: '18' }),
            el('line', { x1: '6', y1: '6', x2: '18', y2: '18' })
        ),
        check: el('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2' },
            el('polyline', { points: '20 6 9 17 4 12' })
        )
    };
    
    // Polling helper
    const pollPrediction = (predictionId, onSuccess, onError, onProgress) => {
        const pollInterval = data.pollInterval || 3000;
        let attempts = 0;
        const maxAttempts = 120;
        
        const poll = async () => {
            attempts++;
            if (attempts > maxAttempts) {
                onError(i18n.errorNetwork || 'Generation timed out.');
                return;
            }
            
            try {
                const response = await $.ajax({
                    url: data.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_poll_prediction',
                        nonce: data.nonce,
                        prediction_id: predictionId
                    }
                });
                
                if (response.success) {
                    if (response.data.status === 'succeeded' && response.data.image_url) {
                        onSuccess(response.data.image_url);
                    } else if (response.data.status === 'failed' || response.data.status === 'canceled') {
                        onError(response.data.message || 'Generation failed.');
                    } else {
                        onProgress && onProgress(response.data.status, attempts);
                        setTimeout(poll, pollInterval);
                    }
                } else {
                    onError(response.data.message || 'Failed to check status.');
                }
            } catch (err) {
                onError(i18n.errorNetwork || 'Network error.');
            }
        };
        
        poll();
    };
    
    // Notice Component
    function Notice({ type, message, onDismiss }) {
        if (!message) return null;
        return el('div', { className: `ais-notice ais-notice-${type}` },
            el('span', null, message),
            onDismiss && el('button', { className: 'ais-notice-dismiss', onClick: onDismiss }, icons.x)
        );
    }
    
    // Step Indicator
    function StepIndicator({ currentStep, steps }) {
        return el('div', { className: 'ais-steps' },
            steps.map((label, idx) => {
                const stepNum = idx + 1;
                const isActive = stepNum === currentStep;
                const isCompleted = stepNum < currentStep;
                const className = `ais-step${isActive ? ' active' : ''}${isCompleted ? ' completed' : ''}`;
                
                return el('div', { key: idx, className },
                    el('div', { className: 'ais-step-number' }, isCompleted ? '✓' : stepNum),
                    el('div', { className: 'ais-step-label' }, label)
                );
            })
        );
    }
    
    // Image Upload Component
    function ImageUpload({ label, value, onChange }) {
        const [preview, setPreview] = useState('');
        
        useEffect(() => {
            if (value) {
                wp.media.attachment(value).fetch().then(function(attachment) {
                    setPreview(attachment.url);
                });
            } else {
                setPreview('');
            }
        }, [value]);
        
        const openMedia = () => {
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
        
        const remove = (e) => {
            e.stopPropagation();
            onChange(null);
            setPreview('');
        };
        
        return el('div', { className: 'ais-upload-item' },
            el('span', { className: 'ais-upload-label' }, label),
            el('div', { 
                className: `ais-upload-box${preview ? ' has-image' : ''}`, 
                onClick: openMedia 
            },
                preview 
                    ? el(Fragment, null,
                        el('img', { src: preview, alt: label }),
                        el('button', { className: 'ais-upload-remove', onClick: remove }, icons.x)
                    )
                    : el(Fragment, null,
                        icons.plus,
                        el('span', { className: 'ais-upload-text' }, i18n.tapToUpload || 'Tap to upload')
                    )
            )
        );
    }
    
    // Select Component
    function Select({ label, value, options, onChange }) {
        return el('div', { className: 'ais-form-group' },
            el('label', { className: 'ais-form-label' }, label),
            el('select', { 
                className: 'ais-select', 
                value: value, 
                onChange: (e) => onChange(e.target.value) 
            },
                Object.entries(options).map(([val, text]) => 
                    el('option', { key: val, value: val }, text)
                )
            )
        );
    }
    
    // Pose Selector Component
    function PoseSelector({ poses, selectedPose, onSelect, editedPose, onEdit }) {
        return el('div', { className: 'ais-pose-selector' },
            el('div', { className: 'ais-pose-list' },
                poses.map((pose, idx) => 
                    el('div', {
                        key: idx,
                        className: `ais-pose-item${selectedPose === idx ? ' selected' : ''}`,
                        onClick: () => onSelect(idx)
                    },
                        el('span', { className: 'ais-pose-num' }, idx + 1),
                        el('span', { className: 'ais-pose-text' }, pose)
                    )
                )
            ),
            selectedPose !== null && el('div', { className: 'ais-pose-edit' },
                el('label', { className: 'ais-form-label' }, i18n.editPose || 'Edit pose (optional)'),
                el('textarea', {
                    className: 'ais-textarea',
                    value: editedPose || poses[selectedPose],
                    onChange: (e) => onEdit(e.target.value),
                    rows: 3
                })
            )
        );
    }
    
    // Result Component
    function ResultDisplay({ imageUrl, onSave, saving, saved, onStartOver }) {
        return el('div', { className: 'ais-result-container ais-fade-in' },
            el('div', { className: 'ais-result-image' },
                el('img', { src: imageUrl, alt: 'Generated' })
            ),
            el('div', { className: 'ais-btn-row' },
                el('button', { 
                    className: `ais-btn ${saved ? 'ais-btn-success' : 'ais-btn-primary'}`,
                    onClick: onSave,
                    disabled: saving || saved
                },
                    saving && el('span', { className: 'ais-spinner' }),
                    saved ? (i18n.saved || 'Saved!') : (saving ? (i18n.saving || 'Saving...') : (i18n.saveToLibrary || 'Save to Library'))
                ),
                el('a', { 
                    className: 'ais-btn ais-btn-outline',
                    href: imageUrl,
                    target: '_blank',
                    download: 'ai-influencer-image.png'
                }, i18n.downloadImage || 'Download'),
                el('button', { 
                    className: 'ais-btn ais-btn-secondary',
                    onClick: onStartOver
                }, i18n.startOver || 'Start Over')
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
        const [saved, setSaved] = useState(false);
        const [resultImage, setResultImage] = useState('');
        const [error, setError] = useState('');
        const [success, setSuccess] = useState('');
        
        // Calculate current step
        const getStep = () => {
            if (resultImage) return 4;
            if (poses.length > 0) return 3;
            if (identity && outfit && background) return 2;
            return 1;
        };
        
        const generatePoses = async () => {
            if (!background) {
                setError(i18n.errorUploadAll || 'Please upload a background image.');
                return;
            }
            
            setLoading(true);
            setError('');
            setPoses([]);
            setSelectedPose(null);
            
            try {
                const response = await $.ajax({
                    url: data.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_generate_poses',
                        nonce: data.nonce,
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
                setError(i18n.errorNetwork || 'Network error.');
            }
            
            setLoading(false);
        };
        
        const synthesizeImage = async () => {
            if (!identity || !outfit || !background || selectedPose === null) {
                setError(i18n.errorSelectPose || 'Please complete all fields.');
                return;
            }
            
            setGenerating(true);
            setError('');
            setResultImage('');
            
            const posePrompt = editedPose || poses[selectedPose];
            
            try {
                if (data.asyncMode) {
                    const response = await $.ajax({
                        url: data.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_image_async',
                            nonce: data.nonce,
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
                            }
                        );
                    } else {
                        setError(response.data?.message || 'Failed to start generation.');
                        setGenerating(false);
                    }
                } else {
                    const response = await $.ajax({
                        url: data.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_image',
                            nonce: data.nonce,
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
                setError(i18n.errorNetwork || 'Network error.');
                setGenerating(false);
            }
        };
        
        const saveToMedia = async () => {
            setSaving(true);
            setError('');
            
            try {
                const response = await $.ajax({
                    url: data.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_save_to_media',
                        nonce: data.nonce,
                        image_url: resultImage
                    }
                });
                
                if (response.success) {
                    setSaved(true);
                } else {
                    setError(response.data.message || 'Failed to save.');
                }
            } catch (err) {
                setError(i18n.errorNetwork || 'Network error.');
            }
            
            setSaving(false);
        };
        
        const startOver = () => {
            setIdentity(null);
            setOutfit(null);
            setBackground(null);
            setPoses([]);
            setSelectedPose(null);
            setEditedPose('');
            setResultImage('');
            setSaved(false);
            setError('');
            setSuccess('');
        };
        
        const steps = [i18n.step1 || 'Upload', i18n.step2 || 'Style', i18n.step3 || 'Pose', i18n.step4 || 'Result'];
        
        return el('div', { className: 'ais-single-form' },
            el(StepIndicator, { currentStep: getStep(), steps: steps }),
            
            el(Notice, { type: 'error', message: error, onDismiss: () => setError('') }),
            el(Notice, { type: 'success', message: success, onDismiss: () => setSuccess('') }),
            
            // Step 1: Upload Images
            !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.step1 || 'Upload Photos'),
                el('div', { className: 'ais-upload-grid' },
                    el(ImageUpload, { label: i18n.uploadIdentity || 'Identity', value: identity, onChange: setIdentity }),
                    el(ImageUpload, { label: i18n.uploadOutfit || 'Outfit', value: outfit, onChange: setOutfit }),
                    el(ImageUpload, { label: i18n.uploadBackground || 'Background', value: background, onChange: setBackground })
                )
            ),
            
            // Step 2: Settings
            !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.step2 || 'Choose Style'),
                el('div', { className: 'ais-settings-row' },
                    el(Select, { 
                        label: i18n.gender || 'Gender', 
                        value: gender, 
                        options: data.genderOptions, 
                        onChange: setGender 
                    }),
                    el(Select, { 
                        label: i18n.poseStyle || 'Pose Style', 
                        value: posePreset, 
                        options: data.posePresets, 
                        onChange: setPosePreset 
                    })
                ),
                el('button', {
                    className: 'ais-btn ais-btn-primary',
                    onClick: generatePoses,
                    disabled: loading || !background
                },
                    loading && el('span', { className: 'ais-spinner' }),
                    loading ? (i18n.analyzing || 'Analyzing...') : (i18n.generatePoses || 'Generate Poses')
                )
            ),
            
            // Step 3: Pose Selection
            poses.length > 0 && !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.selectPose || 'Select a Pose'),
                el(PoseSelector, {
                    poses: poses,
                    selectedPose: selectedPose,
                    onSelect: (idx) => { setSelectedPose(idx); setEditedPose(''); },
                    editedPose: editedPose,
                    onEdit: setEditedPose
                }),
                el('button', {
                    className: 'ais-btn ais-btn-primary',
                    onClick: synthesizeImage,
                    disabled: generating || selectedPose === null || !identity || !outfit
                },
                    generating && el('span', { className: 'ais-spinner' }),
                    generating ? (i18n.generating || 'Generating...') : (i18n.generateImage || 'Generate Image')
                ),
                generating && el('div', { className: 'ais-progress' },
                    el('div', { className: 'ais-progress-bar' })
                )
            ),
            
            // Step 4: Result
            resultImage && el('div', { className: 'ais-card' },
                el(ResultDisplay, {
                    imageUrl: resultImage,
                    onSave: saveToMedia,
                    saving: saving,
                    saved: saved,
                    onStartOver: startOver
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
        const [saved, setSaved] = useState(false);
        const [resultImage, setResultImage] = useState('');
        const [error, setError] = useState('');
        const [success, setSuccess] = useState('');
        
        const getStep = () => {
            if (resultImage) return 4;
            if (poses.length > 0) return 3;
            if (identity1 && outfit1 && identity2 && outfit2 && background) return 2;
            return 1;
        };
        
        const generatePoses = async () => {
            if (!background) {
                setError(i18n.errorUploadAll || 'Please upload a background image.');
                return;
            }
            
            setLoading(true);
            setError('');
            setPoses([]);
            setSelectedPose(null);
            
            try {
                const response = await $.ajax({
                    url: data.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_generate_dual_poses',
                        nonce: data.nonce,
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
                setError(i18n.errorNetwork || 'Network error.');
            }
            
            setLoading(false);
        };
        
        const synthesizeImage = async () => {
            if (!identity1 || !outfit1 || !identity2 || !outfit2 || !background || selectedPose === null) {
                setError(i18n.errorSelectPose || 'Please complete all fields.');
                return;
            }
            
            setGenerating(true);
            setError('');
            setResultImage('');
            
            const posePrompt = editedPose || poses[selectedPose];
            
            try {
                if (data.asyncMode) {
                    const response = await $.ajax({
                        url: data.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_dual_image_async',
                            nonce: data.nonce,
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
                            }
                        );
                    } else {
                        setError(response.data?.message || 'Failed to start generation.');
                        setGenerating(false);
                    }
                } else {
                    const response = await $.ajax({
                        url: data.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ais_synthesize_dual_image',
                            nonce: data.nonce,
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
                setError(i18n.errorNetwork || 'Network error.');
                setGenerating(false);
            }
        };
        
        const saveToMedia = async () => {
            setSaving(true);
            setError('');
            
            try {
                const response = await $.ajax({
                    url: data.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ais_save_to_media',
                        nonce: data.nonce,
                        image_url: resultImage
                    }
                });
                
                if (response.success) {
                    setSaved(true);
                } else {
                    setError(response.data.message || 'Failed to save.');
                }
            } catch (err) {
                setError(i18n.errorNetwork || 'Network error.');
            }
            
            setSaving(false);
        };
        
        const startOver = () => {
            setIdentity1(null);
            setOutfit1(null);
            setIdentity2(null);
            setOutfit2(null);
            setBackground(null);
            setPoses([]);
            setSelectedPose(null);
            setEditedPose('');
            setResultImage('');
            setSaved(false);
            setError('');
            setSuccess('');
        };
        
        const steps = [i18n.step1 || 'Upload', i18n.step2 || 'Style', i18n.step3 || 'Pose', i18n.step4 || 'Result'];
        
        return el('div', { className: 'ais-dual-form' },
            el(StepIndicator, { currentStep: getStep(), steps: steps }),
            
            el(Notice, { type: 'error', message: error, onDismiss: () => setError('') }),
            el(Notice, { type: 'success', message: success, onDismiss: () => setSuccess('') }),
            
            // Model A
            !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.modelA || 'Model A'),
                el('div', { className: 'ais-model-section' },
                    el('div', { className: 'ais-upload-grid' },
                        el(ImageUpload, { label: i18n.uploadIdentity || 'Identity', value: identity1, onChange: setIdentity1 }),
                        el(ImageUpload, { label: i18n.uploadOutfit || 'Outfit', value: outfit1, onChange: setOutfit1 })
                    ),
                    el(Select, { 
                        label: i18n.gender || 'Gender', 
                        value: gender1, 
                        options: data.genderOptions, 
                        onChange: setGender1 
                    })
                )
            ),
            
            // Model B
            !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.modelB || 'Model B'),
                el('div', { className: 'ais-model-section' },
                    el('div', { className: 'ais-upload-grid' },
                        el(ImageUpload, { label: i18n.uploadIdentity || 'Identity', value: identity2, onChange: setIdentity2 }),
                        el(ImageUpload, { label: i18n.uploadOutfit || 'Outfit', value: outfit2, onChange: setOutfit2 })
                    ),
                    el(Select, { 
                        label: i18n.gender || 'Gender', 
                        value: gender2, 
                        options: data.genderOptions, 
                        onChange: setGender2 
                    })
                )
            ),
            
            // Background & Settings
            !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.background || 'Background'),
                el('div', { className: 'ais-upload-grid' },
                    el(ImageUpload, { label: i18n.uploadBackground || 'Background', value: background, onChange: setBackground })
                ),
                el(Select, { 
                    label: i18n.poseStyle || 'Pose Style', 
                    value: posePreset, 
                    options: data.posePresets, 
                    onChange: setPosePreset 
                }),
                el('button', {
                    className: 'ais-btn ais-btn-primary',
                    onClick: generatePoses,
                    disabled: loading || !background
                },
                    loading && el('span', { className: 'ais-spinner' }),
                    loading ? (i18n.analyzing || 'Analyzing...') : (i18n.generatePoses || 'Generate Poses')
                )
            ),
            
            // Pose Selection
            poses.length > 0 && !resultImage && el('div', { className: 'ais-card ais-fade-in' },
                el('h3', { className: 'ais-card-title' }, i18n.selectPose || 'Select a Pose'),
                el(PoseSelector, {
                    poses: poses,
                    selectedPose: selectedPose,
                    onSelect: (idx) => { setSelectedPose(idx); setEditedPose(''); },
                    editedPose: editedPose,
                    onEdit: setEditedPose
                }),
                el('button', {
                    className: 'ais-btn ais-btn-primary',
                    onClick: synthesizeImage,
                    disabled: generating || selectedPose === null || !identity1 || !outfit1 || !identity2 || !outfit2
                },
                    generating && el('span', { className: 'ais-spinner' }),
                    generating ? (i18n.generating || 'Generating...') : (i18n.generateImage || 'Generate Image')
                ),
                generating && el('div', { className: 'ais-progress' },
                    el('div', { className: 'ais-progress-bar' })
                )
            ),
            
            // Result
            resultImage && el('div', { className: 'ais-card' },
                el(ResultDisplay, {
                    imageUrl: resultImage,
                    onSave: saveToMedia,
                    saving: saving,
                    saved: saved,
                    onStartOver: startOver
                })
            )
        );
    }
    
    // Main App
    function App() {
        const [mode, setMode] = useState(data.defaultMode || 'single');
        const showTabs = data.showTabs !== false;
        
        return el('div', { className: 'ais-app-container' },
            showTabs && el('div', { className: 'ais-tabs-nav' },
                el('button', {
                    className: `ais-tab-btn${mode === 'single' ? ' active' : ''}`,
                    onClick: () => setMode('single')
                }, i18n.singleModel || 'Single'),
                el('button', {
                    className: `ais-tab-btn${mode === 'dual' ? ' active' : ''}`,
                    onClick: () => setMode('dual')
                }, i18n.dualModels || 'Duo')
            ),
            mode === 'single' ? el(SingleModelForm) : el(DualModelForm)
        );
    }
    
    // Mount
    document.addEventListener('DOMContentLoaded', function() {
        const root = document.getElementById('ais-frontend-root');
        if (root && wp.element) {
            wp.element.render(el(App), root);
        }
    });
    
})(jQuery, wp);
