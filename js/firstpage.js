document.addEventListener("DOMContentLoaded", () => {
    // Modal elements
    const createPostLabel = document.querySelector('.create .btn-primary');
    const createPostInput = document.querySelector('.create-post input');
    const createPostModal = document.querySelector('#createPostModal');
    const closeModal = document.querySelector('.modal .close');
    const mediaUpload = document.querySelector('#media-upload');
    const postActionIcons = document.querySelectorAll('.post-actions i');
    const fileNameDisplay = document.querySelector('.create-post .file-name');
    const postForm = document.getElementById('create-post-form');

    // Open create post modal
    if (createPostLabel && createPostInput && createPostModal) {
        createPostLabel.addEventListener('click', () => {
            createPostModal.classList.remove('hidden');
        });
        createPostInput.addEventListener('click', () => {
            createPostModal.classList.remove('hidden');
        });
    }

    // Close create post modal
    if (closeModal && createPostModal) {
        closeModal.addEventListener('click', () => {
            createPostModal.classList.add('hidden');
            if (mediaUpload) mediaUpload.value = '';
            if (fileNameDisplay) fileNameDisplay.textContent = '';
        });
    }

    // Close modal when clicking outside
    if (createPostModal) {
        createPostModal.addEventListener('click', (e) => {
            if (e.target === createPostModal) {
                createPostModal.classList.add('hidden');
                if (mediaUpload) mediaUpload.value = '';
                if (fileNameDisplay) fileNameDisplay.textContent = '';
            }
        });
    }

    // Handle post action icons for media upload
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

    // Handle asynchronous post creation
    if (postForm) {
        postForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const modal = document.getElementById('createPostModal');
            const errorDiv = modal.querySelector('.error-message');

            fetch('firstpage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Append new post to feeds
                    const feeds = document.querySelector('.feeds');
                    const newPost = document.createElement('div');
                    newPost.classList.add('feed');
                    newPost.innerHTML = `
                        <div class="head">
                            <div class="user">
                                <div class="profile-photo">
                                    <img src="${data.post.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                                </div>
                                <div class="info">
                                    <h3>${data.post.username}</h3>
                                    <small>${new Date(data.post.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' })}</small>
                                </div>
                            </div>
                            <span class="edit">
                                <i class="uil uil-ellipsis-h"></i>
                                <div class="edit-dropdown hidden">
                                    <div class="edit-option" data-post-id="${data.post.post_id}">Edit</div>
                                    <form action="firstpage.php" method="POST" style="display: inline;">
                                        <input type="hidden" name="post_id" value="${data.post.post_id}">
                                        <input type="hidden" name="delete_post" value="1">
                                        <button type="submit" class="delete-option" onclick="return confirm('Are you sure you want to delete this post?');">Delete</button>
                                    </form>
                                </div>
                            </span>
                        </div>
                        ${data.post.media_type ? `
                            <div class="photo">
                                ${data.post.media_type === 'image' ? `
                                    <img src="${data.post.media_url}" alt="Post image">
                                ` : data.post.media_type === 'video' ? `
                                    <video autoplay muted loop controls>
                                        <source src="${data.post.media_url}" type="video/mp4">
                                    </video>
                                ` : `
                                    <div class="file-post">
                                        <p class="file-name">${data.post.content}</p>
                                        <a href="${data.post.media_url}" download class="btn btn-primary">Download</a>
                                    </div>
                                `}
                            </div>
                        ` : ''}
                        <div class="action-buttons">
                            <div class="interaction-buttons">
                                <span class="like-btn" data-post-id="${data.post.post_id}" data-like-count="0" data-likers="" data-liker-pics="">
                                    <i class="uil uil-heart"></i>
                                </span>
                                <span><i class="uil uil-comment-dots"></i></span>
                                <span><i class="uil uil-share"></i></span>
                            </div>
                            <div class="bookmark">
                                <span><i class="uil uil-bookmark"></i></span>
                            </div>
                        </div>
                        <div class="liked-by">
                            <div class="liker-info" style="display: none;"></div>
                        </div>
                        ${data.post.description ? `
                            <div class="description">
                                <p><b>${data.post.username}</b>: ${data.post.description}</p>
                            </div>
                        ` : ''}
                        <div class="comments text-muted">View all comments</div>
                        <div id="editPostModal-${data.post.post_id}" class="modal hidden">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h3>Edit Post Description</h3>
                                    <span class="close">×</span>
                                </div>
                                <div class="modal-body">
                                    <form action="firstpage.php" method="POST">
                                        <input type="hidden" name="post_id" value="${data.post.post_id}">
                                        <input type="hidden" name="edit_post" value="1">
                                        <textarea name="new_description" rows="4" placeholder="Edit your description...">${data.post.description || ''}</textarea>
                                        <button type="submit" class="btn btn-primary">Save</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    `;
                    feeds.insertBefore(newPost, feeds.firstChild);
                    // Reset form and close modal
                    postForm.reset();
                    modal.classList.add('hidden');
                    if (fileNameDisplay) fileNameDisplay.textContent = '';
                    if (errorDiv) errorDiv.remove();
                } else {
                    // Show error in modal
                    if (!errorDiv) {
                        const newErrorDiv = document.createElement('div');
                        newErrorDiv.classList.add('error-message');
                        newErrorDiv.textContent = data.error;
                        modal.querySelector('.modal-body').insertBefore(newErrorDiv, postForm);
                    } else {
                        errorDiv.textContent = data.error;
                    }
                }
            })
            .catch(error => {
                console.error('Error creating post:', error);
                if (!errorDiv) {
                    const newErrorDiv = document.createElement('div');
                    newErrorDiv.classList.add('error-message');
                    newErrorDiv.textContent = 'An error occurred while posting.';
                    modal.querySelector('.modal-body').insertBefore(newErrorDiv, postForm);
                } else {
                    errorDiv.textContent = 'An error occurred while posting.';
                }
            });
        });
    }

    // Handle likes with event delegation
    document.body.addEventListener('click', function(e) {
        const likeBtn = e.target.closest('.like-btn');
        if (!likeBtn) return;

        e.preventDefault();
        const postId = likeBtn.getAttribute('data-post-id');
        let likeCount = parseInt(likeBtn.getAttribute('data-like-count'));
        let likerUsernames = likeBtn.getAttribute('data-likers').split(',').filter(Boolean);
        let likerProfilePics = likeBtn.getAttribute('data-liker-pics').split(',').filter(Boolean);
        const likeIcon = likeBtn.querySelector('i');
        const likedBy = likeBtn.closest('.feed').querySelector('.liked-by .liker-info');
        const isLiked = likeIcon.classList.contains('liked');
        const currentUser = '<?php echo htmlspecialchars($user['username']); ?>';
        const currentUserPic = '<?php echo htmlspecialchars($user['profile_picture'] ?? './profile_pics/profile.jpg'); ?>';

        // Optimistic UI update
        if (isLiked) {
            likeIcon.classList.remove('liked');
            likeCount--;
            likerUsernames = likerUsernames.filter(name => name !== currentUser);
            likerProfilePics = likerProfilePics.filter(pic => pic !== currentUserPic);
        } else {
            likeIcon.classList.add('liked');
            likeCount++;
            if (!likerUsernames.includes(currentUser)) {
                likerUsernames.unshift(currentUser);
                likerProfilePics.unshift(currentUserPic);
            }
        }

        // Update like button attributes
        likeBtn.setAttribute('data-like-count', likeCount);
        likeBtn.setAttribute('data-likers', likerUsernames.join(','));
        likeBtn.setAttribute('data-liker-pics', likerProfilePics.join(','));

        // Update liked-by section
        if (likeCount > 0) {
            let avatarsHTML = '';
            for (let i = 0; i < Math.min(likerUsernames.length, 3); i++) {
                avatarsHTML += `
                    <div class="profile-photo avatar-${i + 1}">
                        <img src="${likerProfilePics[i] || './profile_pics/profile.jpg'}" alt="Liker Profile">
                    </div>
                `;
            }
            let text = '';
            if (likeCount === 1) {
                text = `Liked by <b>${likerUsernames[0]}</b>`;
            } else if (likeCount === 2) {
                text = `Liked by <b>${likerUsernames[0]}</b> and <b>${likerUsernames[1]}</b>`;
            } else {
                text = `Liked by <b>${likerUsernames[0]}</b> and <b>${likeCount - 1} others</b>`;
            }
            likedBy.innerHTML = `
                <div class="liker-avatars">${avatarsHTML}</div>
                <p>${text}</p>
            `;
            likedBy.style.display = 'block';
        } else {
            likedBy.style.display = 'none';
        }

        // Asynchronous server update
        fetch('firstpage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `like_post=1&post_id=${postId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update with server data
                likeBtn.setAttribute('data-like-count', data.like_count);
                likeBtn.setAttribute('data-likers', data.liker_usernames);
                likeBtn.setAttribute('data-liker-pics', data.liker_profile_pictures);
                likeIcon.classList.toggle('liked', data.is_liked);
                // Update liked-by section
                if (data.like_count > 0) {
                    let avatarsHTML = '';
                    const usernames = (data.liker_usernames || '').split(',').filter(Boolean);
                    const profilePics = (data.liker_profile_pictures || '').split(',').filter(Boolean);
                    for (let i = 0; i < Math.min(usernames.length, 3); i++) {
                        avatarsHTML += `
                            <div class="profile-photo avatar-${i + 1}">
                                <img src="${profilePics[i] || './profile_pics/profile.jpg'}" alt="Liker Profile">
                            </div>
                        `;
                    }
                    let text = '';
                    if (data.like_count === 1) {
                        text = `Liked by <b>${usernames[0]}</b>`;
                    } else if (data.like_count === 2) {
                        text = `Liked by <b>${usernames[0]}</b> and <b>${usernames[1]}</b>`; // Fixed typo: usenames -> usernames
                    } else {
                        text = `Liked by <b>${usernames[0]}</b> and <b>${data.like_count - 1} others</b>`;
                    }
                    likedBy.innerHTML = `
                        <div class="liker-avatars">${avatarsHTML}</div>
                        <p>${text}</p>
                    `;
                    likedBy.style.display = 'block';
                } else {
                    likedBy.style.display = 'none';
                }
            } else {
                // Revert UI on error
                likeIcon.classList.toggle('liked', isLiked);
                likeBtn.setAttribute('data-like-count', isLiked ? likeCount + 1 : likeCount - 1);
                likedBy.innerHTML = '';
                likedBy.style.display = likeCount > 0 ? 'block' : 'none';
            }
        })
        .catch(error => {
            console.error('Error updating like:', error);
            // Revert UI on error
            likeIcon.classList.toggle('liked', isLiked);
            likeBtn.setAttribute('data-like-count', isLiked ? likeCount + 1 : likeCount - 1);
            likedBy.innerHTML = '';
            likedBy.style.display = likeCount > 0 ? 'block' : 'none';
        });
    });

    // SIDEBAR
    const menuItems = document.querySelectorAll('.menu-item');

    // Remove active class from all menu items
    const changeActiveItem = () => {
        menuItems.forEach(item => {
            item.classList.remove('active');
        });
    };

    menuItems.forEach(item => {
        item.addEventListener('click', () => {
            changeActiveItem();
            item.classList.add('active');
            if (item.id !== 'notifications') {
                document.querySelector('.notifications-popup').style.display = 'none';
            } else {
                document.querySelector('.notifications-popup').style.display = 'block';
                document.querySelector('#notifications .notification-count').style.display = 'none';
            }
        });
    });

    // MESSAGES
    const messagesNotification = document.querySelector('#messages-notification');
    const messages = document.querySelector('.messages');
    const message = messages.querySelectorAll('.message');
    const messageSearch = document.querySelector('#message-search');

    // Searches chats
    const searchMessage = () => {
        const val = messageSearch.value.toLowerCase();
        message.forEach(chat => {
            const nameElement = chat.querySelector('h5');
            const name = nameElement ? nameElement.textContent.toLowerCase() : '';
            if (name.includes(val)) {
                chat.style.display = 'flex';
            } else {
                chat.style.display = 'none';
            }
        });
    };

    // Search chat
    if (messageSearch) {
        messageSearch.addEventListener('keyup', searchMessage);
    }

    // Highlight messages card when messages menu item is clicked
    if (messagesNotification && messages) {
        messagesNotification.addEventListener('click', () => {
            messages.style.boxShadow = '0 0 1rem var(--color-primary)';
            messagesNotification.querySelector('.notification-count').style.display = 'none';
            setTimeout(() => {
                messages.style.boxShadow = 'none';
            }, 2000);
        });
    }

    

    // Handle edit dropdown toggle
    document.body.addEventListener('click', (e) => {
        const editIcon = e.target.closest('.edit i');
        if (editIcon) {
            e.stopPropagation();
            const dropdown = editIcon.nextElementSibling;
            document.querySelectorAll('.edit-dropdown').forEach(d => {
                if (d !== dropdown) d.classList.add('hidden');
            });
            dropdown.classList.toggle('hidden');
        } else if (!e.target.closest('.edit-dropdown')) {
            document.querySelectorAll('.edit-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        }
    });

    // Handle edit option click
    document.body.addEventListener('click', (e) => {
        const editOption = e.target.closest('.edit-option');
        if (editOption) {
            const postId = editOption.getAttribute('data-post-id');
            const editModal = document.getElementById(`editPostModal-${postId}`);
            if (editModal) {
                editModal.classList.remove('hidden');
                editOption.closest('.edit-dropdown').classList.add('hidden');
            }
        }
    });

    // Handle modal close buttons
    document.body.addEventListener('click', (e) => {
        const closeBtn = e.target.closest('.modal .close');
        if (closeBtn) {
            closeBtn.closest('.modal').classList.add('hidden');
        }
    });

    // Close modals when clicking outside
    document.body.addEventListener('click', (e) => {
        const modal = e.target.closest('.modal');
        if (modal && e.target === modal) {
            modal.classList.add('hidden');
        }
    });
});