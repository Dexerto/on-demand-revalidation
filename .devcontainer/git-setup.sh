#!/bin/bash

# This script sets up Git configuration inside the container

# Check if we can write to the .gitconfig file
if [ -f "/home/vscode/.gitconfig" ] && [ ! -w "/home/vscode/.gitconfig" ]; then
    echo "Git config file is not writable, creating a local config"
    # Create a new .gitconfig file in the home directory
    touch /tmp/gitconfig
    export GIT_CONFIG=/tmp/gitconfig
    
    # Copy settings from the mounted .gitconfig if it exists and has content
    if [ -s "/home/vscode/.gitconfig" ]; then
        echo "Copying settings from host .gitconfig"
        # Extract user name and email from the mounted .gitconfig
        GIT_USER_NAME=$(git config --file /home/vscode/.gitconfig --get user.name || echo "")
        GIT_USER_EMAIL=$(git config --file /home/vscode/.gitconfig --get user.email || echo "")
        
        # Set them in the new config if they exist
        if [ ! -z "$GIT_USER_NAME" ]; then
            git config --file /tmp/gitconfig user.name "$GIT_USER_NAME"
            echo "Git user.name set to: $GIT_USER_NAME"
        fi
        
        if [ ! -z "$GIT_USER_EMAIL" ]; then
            git config --file /tmp/gitconfig user.email "$GIT_USER_EMAIL"
            echo "Git user.email set to: $GIT_USER_EMAIL"
        fi
    else
        echo "Setting up new Git configuration"
        # Use default values from environment variables if available
        GIT_USER_NAME=${GIT_USER_NAME:-"$(whoami)"}
        GIT_USER_EMAIL=${GIT_USER_EMAIL:-"$(whoami)@example.com"}
        
        git config --file /tmp/gitconfig user.name "$GIT_USER_NAME"
        git config --file /tmp/gitconfig user.email "$GIT_USER_EMAIL"
        
        echo "Git user.name set to: $GIT_USER_NAME"
        echo "Git user.email set to: $GIT_USER_EMAIL"
    fi
    
    # Set up Git to use SSH for GitHub in the new config
    git config --file /tmp/gitconfig url."git@github.com:".insteadOf "https://github.com/"
    
    # Make the config accessible to the vscode user
    chown vscode:vscode /tmp/gitconfig
    chmod 644 /tmp/gitconfig
    
    # Add to .bashrc to ensure GIT_CONFIG is set in all shells
    echo "export GIT_CONFIG=/tmp/gitconfig" >> /home/vscode/.bashrc
else
    echo "Using standard Git configuration"
    
    # Check if Git user configuration is available
    if [ -f "/home/vscode/.gitconfig" ] && [ -s "/home/vscode/.gitconfig" ]; then
        echo "Using existing Git configuration from host"
    else
        echo "Setting up Git configuration"
        
        # Prompt for Git user name and email if not already set
        if [ -z "$(git config --global user.name)" ]; then
            # Use default values from environment variables if available
            GIT_USER_NAME=${GIT_USER_NAME:-"$(whoami)"}
            git config --global user.name "$GIT_USER_NAME"
            echo "Git user.name set to: $(git config --global user.name)"
        fi
        
        if [ -z "$(git config --global user.email)" ]; then
            # Use default values from environment variables if available
            GIT_USER_EMAIL=${GIT_USER_EMAIL:-"$(whoami)@example.com"}
            git config --global user.email "$GIT_USER_EMAIL"
            echo "Git user.email set to: $(git config --global user.email)"
        fi
    fi
    
    # Set up Git to use SSH for GitHub
    git config --global url."git@github.com:".insteadOf "https://github.com/"
fi

# Test SSH connection to GitHub
echo "Testing SSH connection to GitHub..."
ssh -T -o StrictHostKeyChecking=no git@github.com || true

echo "Git setup complete!"
