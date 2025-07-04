document.addEventListener("DOMContentLoaded", () => {
    // Modal elements
    const createPostLabel = document.querySelector('.create .btn-primary');
    const createPostInput = document.querySelector('.create-post input');
    const modal = document.querySelector('#createPostModal');
    const closeModal = document.querySelector('.modal .close');
    const mediaUpload = document.querySelector('#media-upload');
    const postActionIcons = document.querySelectorAll('.post-actions i');
    const fileNameDisplay = document.querySelector('.create-post .file-name');

    // Open modal
    if (createPostLabel && createPostInput && modal) {
        createPostLabel.addEventListener('click', () => {
            modal.classList.remove('hidden');
        });
        createPostInput.addEventListener('click', () => {
            modal.classList.remove('hidden');
        });
    }

    // Close modal
    if (closeModal) {
        closeModal.addEventListener('click', () => {
            modal.classList.add('hidden');
            if (mediaUpload) mediaUpload.value = '';
            if (fileNameDisplay) fileNameDisplay.textContent = '';
        });
    }

    // Close modal when clicking outside
    window.addEventListener("click", (e) => {
        if (e.target === modal) {
            modal.classList.add('hidden');
            if (mediaUpload) mediaUpload.value = '';
            if (fileNameDisplay) fileNameDisplay.textContent = '';
        }
    });

    // Handle post action icons
    if (mediaUpload && postActionIcons) {
        const acceptTypes = {
            image: 'image/*',
            video: 'video/mp4,video/webm,video/ogg',
            document: '.doc,.docx,.pdf',
            audio: 'audio/mpeg,audio/wav'
        };

        postActionIcons.forEach(icon => {
            icon.addEventListener('click', () => {
                const type = icon.getAttribute('data-type');
                mediaUpload.setAttribute('accept', acceptTypes[type]);
                mediaUpload.click();
            });
        });

        mediaUpload.addEventListener('change', () => {
            const fileName = mediaUpload.files[0]?.name || '';
            if (fileNameDisplay) {
                fileNameDisplay.textContent = fileName ? `Selected file: ${fileName}` : '';
            }
        });
    }



    // Edit dropdown toggle
    document.querySelectorAll('.edit').forEach(edit => {
        const dropdown = edit.querySelector('.edit-dropdown');
        edit.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.edit-dropdown').forEach(d => {
                if (d !== dropdown) d.classList.add('hidden');
            });
            dropdown.classList.toggle('hidden');
        });
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.edit')) {
            document.querySelectorAll('.edit-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        }
    });

    document.querySelectorAll('.edit-option').forEach(option => {
        option.addEventListener('click', () => {
            const postId = option.getAttribute('data-post-id');
            const editModal = document.getElementById(`editPostModal-${postId}`);
            editModal.classList.remove('hidden');
            option.closest('.edit-dropdown').classList.add('hidden');
        });
    });

    document.querySelectorAll('.modal .close').forEach(closeBtn => {
        closeBtn.addEventListener('click', () => {
            closeBtn.closest('.modal').classList.add('hidden');
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.add('hidden');
            }
        });
    });

    // Edit Profile Modal
    const editProfileBtn = document.querySelector('.edit-profile-btn');
    const body = document.querySelector('body');

    if (!editProfileBtn) {
        console.error('Edit Profile button not found!');
    } else {
        editProfileBtn.addEventListener('click', () => {
            console.log('Edit Profile button clicked');
            fetch('edit-profile-modal.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const modal = tempDiv.querySelector('#editProfileModal');

                    if (!modal) {
                        console.error('Modal element not found in response!');
                        return;
                    }

                    body.appendChild(modal);
                    modal.classList.remove('hidden');

                    const closeBtn = modal.querySelector('.close');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', () => {
                            modal.remove();
                        });
                    }

                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) {
                            modal.remove();
                        }
                    });

                    const form = modal.querySelector('#editProfileForm');
                    if (form) {
                        form.addEventListener('submit', (e) => {
                            console.log('Form submitted');
                        });
                    }
                })
                .catch(error => console.error('Error loading modal:', error));
        });
    }
});
