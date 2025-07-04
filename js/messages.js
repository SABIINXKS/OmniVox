document.addEventListener("DOMContentLoaded", () => {
    console.log('messages.js loaded');

    const userItems = document.querySelectorAll('.user-item');
    const chatArea = document.querySelector('.chat-area');
    const searchInput = document.getElementById('user-search');
    const searchResults = document.getElementById('search-results');
    let currentChatUserId = null;

    console.log('Found user items:', userItems.length);

    // Function to format date
    function formatDate(date) {
        const now = new Date();
        const diff = (now - date) / 1000; // Difference in seconds
        if (diff < 60) return 'Just now';
        if (diff < 3600) return `${Math.floor(diff / 60)}m`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
        return date.toLocaleString('en-US', {
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).replace(/,/, '');
    }

    // Function to render a message
    function renderMessage(message, posts) {
        const isSent = message.sender_id == window.currentUserId;
        const messageDiv = document.createElement('div');
        messageDiv.classList.add('message', isSent ? 'sent' : 'received');
        messageDiv.setAttribute('data-message-id', message.message_id);

        // Check if it's a shared post
        const postMatch = message.content.match(/\(Post ID: (\d+)\)$/);
        if (postMatch && posts && posts[postMatch[1]]) {
            const post = posts[postMatch[1]];
            messageDiv.classList.add('post-preview');

            // Extract description and media
            let description = '';
            let mediaContent = '';
            const contentParts = message.content.match(/^Shared post by @\w+: (.*?)(?: \[(image|video|document|audio):(.+?)\])? \(Post ID: \d+\)$/);
            if (contentParts) {
                description = contentParts[1] || post.description || '';
                if (contentParts[2] && contentParts[3]) {
                    const mediaType = contentParts[2];
                    const mediaUrl = contentParts[3];
                    if (mediaType === 'image') {
                        mediaContent = `<img src="${mediaUrl}" alt="Post image">`;
                    } else if (mediaType === 'video') {
                        mediaContent = `
                            <video controls>
                                <source src="${mediaUrl}" type="video/mp4">
                            </video>
                        `;
                    } else if (mediaType === 'document' || mediaType === 'audio') {
                        mediaContent = `
                            <div class="file-post">
                                <p class="file-name">${post.content || 'File'}</p>
                                <a href="${mediaUrl}" download>Download</a>
                            </div>
                        `;
                    }
                }
            }

            messageDiv.innerHTML = `
                <div class="post-header">
                    <img src="${post.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                    <span class="username">${post.username}</span>
                </div>
                <div class="post-content">
                    ${description ? `<div class="post-description">${description}</div>` : ''}
                    ${mediaContent ? `<div class="post-media">${mediaContent}</div>` : ''}
                </div>
                <div class="post-footer">
                    <a href="firstpage.php#post-${post.post_id}">View post</a>
                </div>
                <div class="message-time">${formatDate(new Date(message.created_at))}</div>
            `;
        } else {
            messageDiv.innerHTML = `
                <p>${message.content}</p>
                <div class="message-time">${formatDate(new Date(message.created_at))}</div>
            `;
        }

        return messageDiv.outerHTML;
    }

    // Handle user selection
    userItems.forEach(item => {
        item.addEventListener('click', (e) => {
            if (e.target.closest('.more-options') || e.target.closest('.options-menu')) return;
            console.log('User item clicked:', item.getAttribute('data-user-id'));
            userItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');

            currentChatUserId = item.getAttribute('data-user-id');
            loadConversation(currentChatUserId);
        });
    });

    // Toggle options menu
    document.querySelectorAll('.more-options').forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const userId = button.getAttribute('data-user-id');
            const menu = document.querySelector(`.options-menu[data-user-id="${userId}"]`);
            document.querySelectorAll('.options-menu').forEach(m => m.classList.remove('active'));
            menu.classList.toggle('active');
        });
    });

    // Handle delete chat
    document.querySelectorAll('.delete-chat-btn').forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const userId = button.getAttribute('data-user-id');
            if (confirm('Are you sure you want to delete this chat?')) {
                fetch('messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `delete_chat=true&other_user_id=${userId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const userItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);
                        userItem?.remove();
                        chatArea.innerHTML = '<div class="no-chat">No chat selected, start a chat.</div>';
                        localStorage.setItem('unread_count_update', Date.now());
                        updateLeftPanelUnreadCount();
                        if (!document.querySelector('.followed-users .user-item')) {
                            document.querySelector('.followed-users').innerHTML = '<p class="no-users">No message history yet.</p>';
                        }
                    } else {
                        alert(`Failed to delete chat: ${data.error}`);
                    }
                })
                .catch(error => alert(`Failed to delete chat: ${error.message}`));
            }
        });
    });

    // Close options menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.more-options') && !e.target.closest('.options-menu')) {
            document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('active'));
        }
    });

    // Load conversation
    function loadConversation(userId) {
        console.log('Loading conversation for user ID:', userId);

        // Mark messages as read
        fetch('messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `mark_as_read=true&sender_id=${userId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Messages marked as read');
                localStorage.setItem('unread_count_update', Date.now());
                updateLeftPanelUnreadCount();
                const unreadData = document.querySelector(`.user-item[data-user-id="${userId}"] .unread-data`);
                if (unreadData) unreadData.setAttribute('data-unread', '0');
            } else {
                console.error('Failed to mark messages as read:', data.error);
            }
        })
        .catch(error => console.error('Error marking messages as read:', error));

        // Load conversation
        fetch(`messages.php?get_messages=1&other_user_id=${userId}`)
            .then(response => {
                console.log('Fetch response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Fetched data:', data);
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load messages');
                }
                const user = {
                    username: document.querySelector(`.user-item[data-user-id="${userId}"] .username`)?.textContent || data.messages[0]?.sender_id == userId ? data.messages[0].sender_username : data.messages[0]?.receiver_username || 'Unknown',
                    profile_picture: document.querySelector(`.user-item[data-user-id="${userId}"] img`)?.src || data.messages[0]?.sender_id == userId ? data.messages[0].sender_profile_picture : data.messages[0]?.receiver_profile_picture || './profile_pics/profile.jpg'
                };
                const messages = data.messages || [];
                const posts = data.posts || {};

                chatArea.innerHTML = `
                    <div class="chat-header">
                        <img src="${user.profile_picture}" alt="Profile">
                        <div class="username">${user.username}</div>
                    </div>
                    <div class="chat-messages" id="chat-messages">
                        ${messages.map(msg => renderMessage(msg, posts)).join('')}
                    </div>
                    <div class="chat-input">
                        <textarea id="message-input" placeholder="Type a message..."></textarea>
                        <button onclick="sendMessage(${userId})">Send</button>
                    </div>
                    <div class="error-message" id="error-message" style="display: none;"></div>
                `;

                const chatMessages = document.getElementById('chat-messages');
                chatMessages.scrollTop = chatMessages.scrollHeight;

                // Add Enter key support for sending messages
                const textarea = document.getElementById('message-input');
                textarea.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage(userId);
                    }
                });
            })
            .catch(error => {
                console.error('Error loading conversation:', error);
                chatArea.innerHTML = '<div class="no-chat">Failed to load conversation.</div>';
            });
    }

    // Update unread count in left panel
    function updateLeftPanelUnreadCount() {
        fetch('get_unread_count.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const unreadElement = document.querySelector('.left .unread-count');
                    if (unreadElement) {
                        if (data.unread_count > 0) {
                            unreadElement.textContent = data.unread_count;
                            unreadElement.style.display = 'inline-block';
                        } else {
                            unreadElement.style.display = 'none';
                        }
                    }
                } else {
                    console.error('Error updating unread count:', data.error);
                }
            })
            .catch(error => console.error('Error updating unread count:', error));
    }

    // Send message
    window.sendMessage = function(receiverId) {
        console.log('Sending message to user ID:', receiverId);
        const input = document.getElementById('message-input');
        const content = input.value.trim();
        const errorMessage = document.getElementById('error-message');

        if (!content) {
            errorMessage.textContent = 'Please enter a message.';
            errorMessage.style.display = 'block';
            return;
        }

        fetch('messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `send_message=true&receiver_id=${receiverId}&content=${encodeURIComponent(content)}`
        })
        .then(response => {
            console.log('Send message response status:', response.status);
            return response.text().then(text => {
                console.log('Raw response:', text);
                try {
                    const data = JSON.parse(text);
                    return data;
                } catch (e) {
                    throw new Error('Invalid JSON response: ' + text);
                }
            });
        })
        .then(data => {
            if (data.success) {
                errorMessage.style.display = 'none';
                input.value = '';
                localStorage.setItem('unread_count_update', Date.now());
                updateLastMessage(receiverId, content);
                loadConversation(receiverId); // Refresh conversation
            } else {
                errorMessage.textContent = 'Failed to send message: ' + data.error;
                errorMessage.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error sending message:', error);
            errorMessage.textContent = 'Failed to send message: ' + error.message;
            errorMessage.style.display = 'block';
        });
    };

    // Update last message in conversation list
    function updateLastMessage(userId, content) {
        const userItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);
        if (userItem) {
            const lastMessage = userItem.querySelector('.last-message');
            lastMessage.textContent = content;
            // Move to top of list
            const followedUsers = document.querySelector('.followed-users');
            followedUsers.prepend(userItem);
        }
    }

    // Handle user search
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                searchResults.classList.remove('active');
                searchResults.innerHTML = '';
                return;
            }

            fetch('messages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `search_users=true&query=${encodeURIComponent(query)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    searchResults.innerHTML = data.users.length ? data.users.map(user => `
                        <div class="search-result-item" data-user-id="${user.user_id}" data-username="${user.username}" data-profile-pic="${user.profile_picture || './profile_pics/profile.jpg'}">
                            <img src="${user.profile_picture || './profile_pics/profile.jpg'}" alt="Profile">
                            <div class="username">${user.username}</div>
                        </div>
                    `).join('') : '<div class="search-result-item">No users found</div>';
                    searchResults.classList.add('active');

                    // Handle search result selection
                    document.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const userId = item.getAttribute('data-user-id');
                            const username = item.getAttribute('data-username');
                            const profilePic = item.getAttribute('data-profile-pic');
                            searchInput.value = '';
                            searchResults.classList.remove('active');
                            searchResults.innerHTML = '';

                            // Check if conversation exists, else create new
                            if (!document.querySelector(`.user-item[data-user-id="${userId}"]`)) {
                                const followedUsers = document.querySelector('.followed-users');
                                const newUserItem = document.createElement('div');
                                newUserItem.className = 'user-item';
                                newUserItem.setAttribute('data-user-id', userId);
                                newUserItem.setAttribute('data-username', username);
                                newUserItem.setAttribute('data-profile-pic', profilePic);
                                newUserItem.innerHTML = `
                                    <img src="${profilePic}" alt="Profile">
                                    <div class="user-info">
                                        <div class="username">${username}</div>
                                        <div class="last-message">No messages yet</div>
                                    </div>
                                    <div class="unread-data" data-unread="0"></div>
                                    <div class="more-options" data-user-id="${userId}">
                                        <i class="uil uil-ellipsis-h"></i>
                                    </div>
                                    <div class="options-menu" data-user-id="${userId}">
                                        <a href="profile.php?user_id=${userId}">Open Profile</a>
                                        <button class="delete-chat-btn" data-user-id="${userId}">Delete Chat</button>
                                    </div>
                                `;
                                followedUsers.querySelector('.no-users')?.remove();
                                followedUsers.insertBefore(newUserItem, followedUsers.querySelector('.user-item') || followedUsers.lastChild);
                                newUserItem.querySelector('.more-options').addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    const menu = newUserItem.querySelector('.options-menu');
                                    document.querySelectorAll('.options-menu').forEach(m => m.classList.remove('active'));
                                    menu.classList.toggle('active');
                                });
                                newUserItem.querySelector('.delete-chat-btn').addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    if (confirm('Are you sure you want to delete this chat?')) {
                                        fetch('messages.php', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                            body: `delete_chat=true&other_user_id=${userId}`
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            if (data.success) {
                                                newUserItem.remove();
                                                chatArea.innerHTML = '<div class="no-chat">No chat selected, start a chat.</div>';
                                                localStorage.setItem('unread_count_update', Date.now());
                                                updateLeftPanelUnreadCount();
                                                if (!document.querySelector('.followed-users .user-item')) {
                                                    document.querySelector('.followed-users').innerHTML = '<p class="no-users">No message history yet.</p>';
                                                }
                                            } else {
                                                alert(`Failed to delete chat: ${data.error}`);
                                            }
                                        })
                                        .catch(error => alert(`Failed to delete chat: ${error.message}`));
                                    }
                                });
                                newUserItem.addEventListener('click', (e) => {
                                    if (e.target.closest('.more-options') || e.target.closest('.options-menu')) return;
                                    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                                    newUserItem.classList.add('active');
                                    currentChatUserId = userId;
                                    loadConversation(userId);
                                });
                            }

                            document.querySelectorAll('.user-item').forEach(i => i.classList.remove('active'));
                            document.querySelector(`.user-item[data-user-id="${userId}"]`)?.classList.add('active');
                            currentChatUserId = userId;
                            loadConversation(userId);
                        });
                    });
                } else {
                    searchResults.innerHTML = '<div class="search-result-item">Error: ' + data.error + '</div>';
                    searchResults.classList.add('active');
                }
            })
            .catch(error => {
                searchResults.innerHTML = '<div class="search-result-item">Error: ' + error.message + '</div>';
                searchResults.classList.add('active');
            });
        });
    } else {
        console.warn('Search input element with id "user-search" not found.');
    }

    // Close search results when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-container')) {
            searchResults.classList.remove('active');
            searchResults.innerHTML = '';
        }
    });

    // Ensure logout link works
    const logoutLink = document.getElementById('logout-link');
    if (logoutLink) {
        logoutLink.addEventListener('click', (e) => {
            e.stopPropagation();
            console.log('Logout triggered');
            window.location.href = logoutLink.href;
        });
    }

    // Initial unread count update
    updateLeftPanelUnreadCount();
});