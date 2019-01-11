.EXPORT_ALL_VARIABLES:

BREF_VERSION := 0.3
REGION := us-east-2
PHP_VERSION := 7.2.13
PHP_VERSION_SHORT := 72

# Build the PHP runtimes
runtimes: runtime-default runtime-fpm runtime-console runtime-loop
runtime-default:
	cd runtime/default && sh publish.sh
runtime-fpm:
	cd runtime/fpm && sh publish.sh
runtime-console:
	cd runtime/console && sh publish.sh
runtime-loop:
	cd runtime/loop && sh publish.sh

# Generate and deploy the production version of the website using http://couscous.io
website:
	# See http://couscous.io/
	couscous generate
	netlify deploy --prod --dir=.couscous/generated

# Run a local preview of the website using http://couscous.io
website-preview:
	couscous preview

website-assets: website/template/output.css
website/template/output.css: website/node_modules website/template/styles.css website/tailwind.js
	./website/node_modules/.bin/tailwind build website/template/styles.css -c website/tailwind.js -o website/template/output.css
website/node_modules:
	yarn install

# Deploy the demo functions
demo:
	rm -rf .bref .couscous
	rm -f runtime/default/php.zip
	rm -f runtime/fpm/php.zip
	rm -f runtime/loop/php.zip
	sam package \
		--region us-east-2 \
		--template-file template.yaml \
		--output-template-file output.yaml \
		--s3-bucket bref-demo-us-east-2
	sam deploy \
		--region us-east-2 \
		--template-file output.yaml \
		--stack-name bref-demo \
 		--capabilities CAPABILITY_IAM

.PHONY: runtimes runtime-default runtime-fpm runtime-console runtime-loop website-preview website demo