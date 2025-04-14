const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const glob = require('glob');

// Find all block.json files
const blockEntries = glob.sync('./src/blocks/*/block.json').map(file => {
	const blockDir = path.dirname(file);
	const blockName = path.basename(path.dirname(file));
	return {
		name: blockName,
		path: path.resolve(blockDir, 'index.ts'),
	};
});

// Create entry points for each block
const entries = {
	index: path.resolve(process.cwd(), 'src', 'index.ts'),
};

// Add block entries
blockEntries.forEach(block => {
	entries[block.name] = block.path;
});

module.exports = {
	...defaultConfig,
	entry: entries,
	output: {
		...defaultConfig.output,
		path: path.resolve(process.cwd(), 'build'),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: ['.ts', '.tsx', '.js', '.jsx', '.json'],
		alias: {
			'@': path.resolve(process.cwd(), 'src'),
		},
	},
};
