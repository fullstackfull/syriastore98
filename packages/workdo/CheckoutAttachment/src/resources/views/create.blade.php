<div class="mt-4">
    <h2 class="font-semibold text-xl md:mb-4 mb-3">{{ __('Attachments') }}</h2>
    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
        <div class="grid grid-cols-1 gap-4">
            <div>
                <label class="block mb-2 font-medium md:text-base text-sm">
                    {{ __('Upload Files') }}:
                    <small class="text-gray-500 font-normal">({{ __('You can select multiple files') }})</small>
                </label>

                <div class="flex items-stretch shadow-sm rounded-lg overflow-hidden border border-gray-200">
                    <input type="text"
                           id="attachments"
                           class="form-input w-full rounded-r-none border-r-0"
                           placeholder="{{ __('Choose files') }}"
                           readonly>

                    <input type="file"
                           id="file"
                           name="attachment[]"
                           class="hidden"
                           multiple
                           data-filename="multiple_file_selection"
                           onchange="previewFiles(this)"
                           accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.txt">

                    <label for="file"
                           class="inline-flex items-center gap-2 px-4 py-2 text-white bg-[var(--primary-color)] hover:bg-[var(--primary-color)]/90 text-sm font-medium cursor-pointer transition-all duration-200 rounded-l-none">
                        <i class="ti ti-circle-plus"></i> {{ __('Browse') }}
                    </label>
                </div>

                @if($errors->has('attachment'))
                    <p class="text-sm text-red-500 mt-2">{{ $errors->first('attachment.*') }}</p>
                @endif

                <div id="preview" class="flex flex-wrap gap-2 mt-3"></div>
            </div>
        </div>
    </div>
</div>

<script>
    function previewFiles(input) {
        const preview = document.getElementById('preview');
        const attachmentsInput = document.getElementById('attachments');
        
        preview.innerHTML = '';
        
        if (input.files && input.files.length > 0) {
            // Update the text input with file names
            const fileNames = Array.from(input.files).map(file => file.name);
            attachmentsInput.value = fileNames.length > 1 ? 
                `${fileNames.length} files selected` : 
                fileNames[0];
            
            // Show file previews
            Array.from(input.files).forEach(file => {
                const fileDiv = document.createElement('div');
                fileDiv.className = "relative inline-block";
                
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        fileDiv.innerHTML = `
                            <img src="${e.target.result}" class="w-20 h-20 object-cover rounded-md shadow border">
                            <div class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs cursor-pointer" onclick="removeFile(this, '${file.name}')">×</div>
                        `;
                    };
                    reader.readAsDataURL(file);
                } else {
                    fileDiv.innerHTML = `
                        <div class="w-20 h-20 bg-gray-100 border rounded-md flex flex-col items-center justify-center text-xs text-gray-600">
                            <i class="ti ti-file text-lg mb-1"></i>
                            <span class="text-center px-1">${file.name.split('.').pop().toUpperCase()}</span>
                        </div>
                        <div class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs cursor-pointer" onclick="removeFile(this, '${file.name}')">×</div>
                    `;
                }
                
                preview.appendChild(fileDiv);
            });
        } else {
            attachmentsInput.value = '';
        }
    }
    
    function removeFile(element, fileName) {
        // Remove the preview element
        element.parentElement.remove();
        
        // Update the file input and text display
        const fileInput = document.getElementById('file');
        const attachmentsInput = document.getElementById('attachments');
        const preview = document.getElementById('preview');
        
        // If no more previews, clear the text input
        if (preview.children.length === 0) {
            attachmentsInput.value = '';
            fileInput.value = '';
        } else {
            attachmentsInput.value = `${preview.children.length} files selected`;
        }
    }
</script>