<?php $__env->startSection('page-title', __('API Settings')); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><?php echo e(__('API Settings')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<style>
.api-settings-container {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.api-settings-header {
    background: linear-gradient(135deg, var(--theme-color), var(--theme-color));
    color: white;
    padding: 2rem;
    text-align: center;
    position: relative;
}

.api-settings-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
    opacity: 0.3;
}

.api-settings-title {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 1;
}

.api-settings-subtitle {
    font-size: 1rem;
    opacity: 0.9;
    margin: 0.5rem 0 0 0;
    position: relative;
    z-index: 1;
}

.api-settings-body {
    padding: 2rem;
}

.api-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.api-section:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.section-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f3f4f6;
}

.section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--theme-color), var(--theme-color));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    margin-right: 1rem;
    font-size: 1.2rem;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-label i {
    color: #3b82f6;
    font-size: 1rem;
}

.form-control {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #ffffff;
}

.form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.form-control::placeholder {
    color: #9ca3af;
}

textarea.form-control {
    min-height: 100px;
    resize: vertical;
}

.endpoint-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.endpoint-title-text {
    margin-bottom: 1rem;
}

.endpoint-card ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.endpoint-card li {
    margin-bottom: 0.75rem;
}

.endpoint-card li:last-child {
    margin-bottom: 0;
}

.parameter-section-data {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.parameter-section-data input,
.parameter-section-data textarea {
    margin-bottom: 0.75rem;
}

.parameter-section-data textarea {
    min-height: 80px;
}

.save-button-container {
    text-align: center;
    padding: 2rem;
    background: #f8fafc;
    border-top: 1px solid #e5e7eb;
}

.btn-primary {
    background: linear-gradient(135deg, var(--theme-color), var(--theme-color));
    border: none;
    padding: 0.875rem 2rem;
    border-radius: 50px;
    font-weight: 600;
    font-size: 1rem;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--theme-color), var(--theme-color));
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
}

.btn-primary:active {
    transform: translateY(0);
}

.row {
    margin: 0 -0.75rem;
}

.col-sm-6, .col-sm-12 {
    padding: 0 0.75rem;
}

.alert {
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    border: none;
}

.alert-info {
    background: linear-gradient(135deg, #dbeafe, #e0e7ff);
    color: #1e40af;
    border-left: 4px solid #3b82f6;
}

@media (max-width: 768px) {
    .api-settings-body {
        padding: 1rem;
    }
    
    .api-section {
        padding: 1rem;
    }
    
    .section-header {
        flex-direction: column;
        text-align: center;
    }
    
    .section-icon {
        margin-right: 0;
        margin-bottom: 0.5rem;
    }
}
</style>

<div class="row">
    <div class="col-md-12">
        <div class="api-settings-container">
            <?php echo e(Form::open(['route' => ['api.setting.data'], 'method' => 'post', 'enctype' => 'multipart/form-data'])); ?>

            
            <div class="api-settings-header">
                <h1 class="api-settings-title">
                    <i class="ti ti-api"></i>
                    <?php echo e(__('API Settings')); ?>

                </h1>
                <p class="api-settings-subtitle"><?php echo e(__('Configure your API documentation settings and content')); ?></p>
            </div>

            <div class="api-settings-body">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section_name'])): ?>
                    <input type="hidden" name="app_setting_tab" value="pills-page-tab">
                    <input type="hidden" name="section_name" value="<?php echo e($data['section_slug']); ?>">
                    <input type="hidden" name="array[section_name]" value="<?php echo e($data['section_name']); ?>">
                    <input type="hidden" name="array[section_slug]" value="<?php echo e($data['section_slug']); ?>">
                    <input type="hidden" name="array[array_type]" value="<?php echo e($data['array_type']); ?>">
                    <input type="hidden" name="array[loop_number]" value="<?php echo e($data['loop_number'] ?? 1); ?>">

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = 0; $i < $data['loop_number']; $i++): ?>
                        <!-- Basic Information Section -->
                        <div class="api-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="ti ti-info-circle"></i>
                                </div>
                                <h3 class="section-title"><?php echo e(__('Basic Information')); ?></h3>
                            </div>
                            
                            <div class="row">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['title'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-heading"></i>
                                                <?php echo e($data['section']['title']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][title][slug]" value="<?php echo e($data['section']['title']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][title][label]" value="<?php echo e($data['section']['title']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][title][type]" value="<?php echo e($data['section']['title']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][title][placeholder]" value="<?php echo e($data['section']['title']['placeholder'] ?? ''); ?>">
                                            <input type="text" name="array[section][title][text]" class="form-control" 
                                                   value="<?php echo e($data['section']['title']['text'] ?? ''); ?>"
                                                   placeholder="<?php echo e($data['section']['title']['placeholder']); ?>"
                                                   id="<?php echo e($data['section']['title']['slug']); ?>">
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['description'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-file-text"></i>
                                                <?php echo e($data['section']['description']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][description][slug]" value="<?php echo e($data['section']['description']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][description][label]" value="<?php echo e($data['section']['description']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][description][type]" value="<?php echo e($data['section']['description']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][description][placeholder]" value="<?php echo e($data['section']['description']['placeholder'] ?? ''); ?>">
                                            <textarea name="array[section][description][text]" class="form-control"
                                                      placeholder="<?php echo e($data['section']['description']['placeholder']); ?>"
                                                      id="<?php echo e($data['section']['description']['slug']); ?>"><?php echo e($data['section']['description']['text']); ?></textarea>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>

                        <!-- Getting Started Section -->
                        <div class="api-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="ti ti-rocket"></i>
                                </div>
                                <h3 class="section-title"><?php echo e(__('Getting Started')); ?></h3>
                            </div>
                            
                            <div class="row">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['subtitle'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-h-2"></i>
                                                <?php echo e($data['section']['subtitle']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][subtitle][slug]" value="<?php echo e($data['section']['subtitle']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subtitle][label]" value="<?php echo e($data['section']['subtitle']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subtitle][type]" value="<?php echo e($data['section']['subtitle']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subtitle][placeholder]" value="<?php echo e($data['section']['subtitle']['placeholder'] ?? ''); ?>">
                                            <input type="text" name="array[section][subtitle][text]" class="form-control"
                                                   value="<?php echo e($data['section']['subtitle']['text'] ?? ''); ?>"
                                                   placeholder="<?php echo e($data['section']['subtitle']['placeholder']); ?>"
                                                   id="<?php echo e($data['section']['subtitle']['slug']); ?>">
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['subdescription'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-file-description"></i>
                                                <?php echo e($data['section']['subdescription']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][subdescription][slug]" value="<?php echo e($data['section']['subdescription']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subdescription][label]" value="<?php echo e($data['section']['subdescription']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subdescription][type]" value="<?php echo e($data['section']['subdescription']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][subdescription][placeholder]" value="<?php echo e($data['section']['subdescription']['placeholder'] ?? ''); ?>">
                                            <textarea name="array[section][subdescription][text]" class="form-control"
                                                      placeholder="<?php echo e($data['section']['subdescription']['placeholder']); ?>"
                                                      id="<?php echo e($data['section']['subdescription']['slug']); ?>"><?php echo e($data['section']['subdescription']['text']); ?></textarea>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>

                        <!-- Endpoints Section -->
                        <div class="api-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="ti ti-link"></i>
                                </div>
                                <h3 class="section-title"><?php echo e(__('API Endpoints')); ?></h3>
                            </div>
                            
                            <div class="row">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['endpoint'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-world"></i>
                                                <?php echo e($data['section']['endpoint']['label']); ?>

                                            </label>
                                            <div class="endpoint-card">
                                                <input type="hidden" name="array[section][endpoint][slug]" value="<?php echo e($data['section']['endpoint']['slug'] ?? ''); ?>">
                                                <input type="hidden" name="array[section][endpoint][label]" value="<?php echo e($data['section']['endpoint']['label'] ?? ''); ?>">
                                                <input type="hidden" name="array[section][endpoint][type]" value="<?php echo e($data['section']['endpoint']['type'] ?? ''); ?>">
                                                <input type="hidden" name="array[section][endpoint][placeholder]" value="<?php echo e($data['section']['endpoint']['placeholder'] ?? ''); ?>">
                                                <input type="hidden" name="array[section][endpoint][loop_number]" value="<?php echo e($data['section']['endpoint']['loop_number'] ?? ''); ?>">
                                                
                                                <div class="endpoint-title-text">
                                                    <input type="text" name="array[section][endpoint][text]" class="form-control"
                                                           value="<?php echo e($data['section']['endpoint']['text']); ?>"
                                                           placeholder="<?php echo e($data['section']['endpoint']['placeholder'] ?? 'Enter value'); ?>"
                                                           id="<?php echo e($data['section']['endpoint']['slug'] ?? ''); ?>">
                                                </div>
                                                
                                                <ul>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($j = 0; $j < $data['section']['endpoint']['loop_number']; $j++): ?>
                                                        <li>
                                                            <input type="text" name="array[section][endpoint][endpoint][<?php echo e($j); ?>]" class="form-control"
                                                                   value="<?php echo e($data['section']['endpoint']['endpoint'][$j]); ?>"
                                                                   placeholder="<?php echo e($data['section']['endpoint']['placeholder'] ?? 'Enter endpoint URL'); ?>"
                                                                   id="<?php echo e($data['section']['endpoint']['slug'] ?? 'endpoint_' . $j); ?>">
                                                        </li>
                                                    <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['endpoint_description'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-message-circle"></i>
                                                <?php echo e($data['section']['endpoint_description']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][endpoint_description][slug]" value="<?php echo e($data['section']['endpoint_description']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][endpoint_description][label]" value="<?php echo e($data['section']['endpoint_description']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][endpoint_description][type]" value="<?php echo e($data['section']['endpoint_description']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][endpoint_description][placeholder]" value="<?php echo e($data['section']['endpoint_description']['placeholder'] ?? ''); ?>">
                                            <textarea name="array[section][endpoint_description][text]" class="form-control"
                                                      placeholder="<?php echo e($data['section']['endpoint_description']['placeholder']); ?>"
                                                      id="<?php echo e($data['section']['endpoint_description']['slug']); ?>"><?php echo e($data['section']['endpoint_description']['text']); ?></textarea>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>

                        <!-- Request Parameters Section -->
                        <div class="api-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="ti ti-settings"></i>
                                </div>
                                <h3 class="section-title"><?php echo e(__('Request Parameters')); ?></h3>
                            </div>
                            
                            <div class="row">
                                <div class="col-sm-6">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['request_parameter_title'])): ?>
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-tag"></i>
                                                <?php echo e($data['section']['request_parameter_title']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][request_parameter_title][slug]" value="<?php echo e($data['section']['request_parameter_title']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_title][label]" value="<?php echo e($data['section']['request_parameter_title']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_title][type]" value="<?php echo e($data['section']['request_parameter_title']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_title][placeholder]" value="<?php echo e($data['section']['request_parameter_title']['placeholder'] ?? ''); ?>">
                                            <input type="text" name="array[section][request_parameter_title][text]" class="form-control"
                                                   value="<?php echo e($data['section']['request_parameter_title']['text'] ?? ''); ?>"
                                                   placeholder="<?php echo e($data['section']['request_parameter_title']['placeholder']); ?>"
                                                   id="<?php echo e($data['section']['request_parameter_title']['slug']); ?>">
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['request_parameter_description'])): ?>
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-file-text"></i>
                                                <?php echo e($data['section']['request_parameter_description']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][request_parameter_description][slug]" value="<?php echo e($data['section']['request_parameter_description']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_description][label]" value="<?php echo e($data['section']['request_parameter_description']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_description][type]" value="<?php echo e($data['section']['request_parameter_description']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_description][placeholder]" value="<?php echo e($data['section']['request_parameter_description']['placeholder'] ?? ''); ?>">
                                            <textarea name="array[section][request_parameter_description][text]" class="form-control"
                                                      placeholder="<?php echo e($data['section']['request_parameter_description']['placeholder']); ?>"
                                                      id="<?php echo e($data['section']['request_parameter_description']['slug']); ?>"><?php echo e($data['section']['request_parameter_description']['text']); ?></textarea>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['request_parameter_sub_data'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-list"></i>
                                                <?php echo e($data['section']['request_parameter_sub_data']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][request_parameter_sub_data][slug]" value="<?php echo e($data['section']['request_parameter_sub_data']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_sub_data][label]" value="<?php echo e($data['section']['request_parameter_sub_data']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_sub_data][type]" value="<?php echo e($data['section']['request_parameter_sub_data']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_sub_data][placeholder]" value="<?php echo e($data['section']['request_parameter_sub_data']['placeholder'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][request_parameter_sub_data][loop_number]" value="<?php echo e($data['section']['request_parameter_sub_data']['loop_number'] ?? ''); ?>">
                                            
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($j = 0; $j < $data['section']['request_parameter_sub_data']['loop_number']; $j++): ?>
                                                <div class="parameter-section-data">
                                                    <input type="text" name="array[section][request_parameter_sub_data][sub_title][<?php echo e($j); ?>]" class="form-control"
                                                           value="<?php echo e($data['section']['request_parameter_sub_data']['sub_title'][$j]); ?>"
                                                           placeholder="<?php echo e(__('Parameter Name')); ?>"
                                                           id="<?php echo e($data['section']['request_parameter_sub_data']['slug'] ?? 'request_parameter_sub_data_' . $j); ?>">
                                                    <textarea name="array[section][request_parameter_sub_data][sub_description][<?php echo e($j); ?>]" class="form-control"
                                                              placeholder="<?php echo e(__('Parameter Description')); ?>"><?php echo e($data['section']['request_parameter_sub_data']['sub_description'][$j]); ?></textarea>
                                                </div>
                                            <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>

                        <!-- Response Data Section -->
                        <div class="api-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="ti ti-code"></i>
                                </div>
                                <h3 class="section-title"><?php echo e(__('Response Data')); ?></h3>
                            </div>
                            
                            <div class="row">
                                <div class="col-sm-6">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['response_data_title'])): ?>
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-tag"></i>
                                                <?php echo e($data['section']['response_data_title']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][response_data_title][slug]" value="<?php echo e($data['section']['response_data_title']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_title][label]" value="<?php echo e($data['section']['response_data_title']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_title][type]" value="<?php echo e($data['section']['response_data_title']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_title][placeholder]" value="<?php echo e($data['section']['response_data_title']['placeholder'] ?? ''); ?>">
                                            <input type="text" name="array[section][response_data_title][text]" class="form-control"
                                                   value="<?php echo e($data['section']['response_data_title']['text'] ?? ''); ?>"
                                                   placeholder="<?php echo e($data['section']['response_data_title']['placeholder']); ?>"
                                                   id="<?php echo e($data['section']['response_data_title']['slug']); ?>">
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['response_data_description'])): ?>
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-file-text"></i>
                                                <?php echo e($data['section']['response_data_description']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][response_data_description][slug]" value="<?php echo e($data['section']['response_data_description']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_description][label]" value="<?php echo e($data['section']['response_data_description']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_description][type]" value="<?php echo e($data['section']['response_data_description']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_description][placeholder]" value="<?php echo e($data['section']['response_data_description']['placeholder'] ?? ''); ?>">
                                            <textarea name="array[section][response_data_description][text]" class="form-control"
                                                      placeholder="<?php echo e($data['section']['response_data_description']['placeholder']); ?>"
                                                      id="<?php echo e($data['section']['response_data_description']['slug']); ?>"><?php echo e($data['section']['response_data_description']['text']); ?></textarea>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($data['section']['response_data_sub_data'])): ?>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="ti ti-list"></i>
                                                <?php echo e($data['section']['response_data_sub_data']['label']); ?>

                                            </label>
                                            <input type="hidden" name="array[section][response_data_sub_data][slug]" value="<?php echo e($data['section']['response_data_sub_data']['slug'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_sub_data][label]" value="<?php echo e($data['section']['response_data_sub_data']['label'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_sub_data][type]" value="<?php echo e($data['section']['response_data_sub_data']['type'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_sub_data][placeholder]" value="<?php echo e($data['section']['response_data_sub_data']['placeholder'] ?? ''); ?>">
                                            <input type="hidden" name="array[section][response_data_sub_data][loop_number]" value="<?php echo e($data['section']['response_data_sub_data']['loop_number'] ?? ''); ?>">
                                            
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($j = 0; $j < $data['section']['response_data_sub_data']['loop_number']; $j++): ?>
                                                <div class="parameter-section-data">
                                                    <input type="text" name="array[section][response_data_sub_data][sub_title][<?php echo e($j); ?>]" class="form-control"
                                                           value="<?php echo e($data['section']['response_data_sub_data']['sub_title'][$j]); ?>"
                                                           placeholder="<?php echo e(__('Response Field Name')); ?>"
                                                           id="<?php echo e($data['section']['response_data_sub_data']['slug'] ?? 'response_data_sub_data_' . $j); ?>">
                                                    <textarea name="array[section][response_data_sub_data][sub_description][<?php echo e($j); ?>]" class="form-control"
                                                              placeholder="<?php echo e(__('Response Field Description')); ?>"><?php echo e($data['section']['response_data_sub_data']['sub_description'][$j]); ?></textarea>
                                                </div>
                                            <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <?php if (app('laratrust')->hasPermission('Edit Store Setting')) : ?>
                <div class="save-button-container">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy"></i>
                        <?php echo e(__('Save Changes')); ?>

                    </button>
                </div>
            <?php endif; // app('laratrust')->permission ?>

            <?php echo Form::close(); ?>

        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/packages/workdo/Api/src/app/Providers/../../resources/views/setting/index.blade.php ENDPATH**/ ?>