<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace PrestaShopBundle\Controller\Admin\Sell\Catalog\Product;

use Exception;
use PrestaShop\PrestaShop\Core\Domain\Category\Query\GetCategoryForEditing;
use PrestaShop\PrestaShop\Core\Domain\Manufacturer\Exception\ManufacturerException;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkDeleteProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkDuplicateProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkToggleProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\DeleteProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\DuplicateProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductPositionCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\CannotBulkDeleteProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\CannotDeleteProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\CannotUpdateProductPositionException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\FeatureValue\Exception\DuplicateFeatureValueAssociationException;
use PrestaShop\PrestaShop\Core\Domain\Product\FeatureValue\Exception\InvalidAssociatedFeatureException;
use PrestaShop\PrestaShop\Core\Domain\Product\Query\GetProductForEditing;
use PrestaShop\PrestaShop\Core\Domain\Product\Query\SearchProductsForAssociation;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\ProductForAssociation;
use PrestaShop\PrestaShop\Core\Domain\Product\SpecificPrice\Exception\SpecificPriceConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Shop\Exception\ShopAssociationNotFound;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\ProductForEditing;
use PrestaShop\PrestaShop\Core\Exception\ProductException;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\ProductFilters;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Entity\ProductDownload;
use PrestaShopBundle\Form\Admin\Product\ProductCategories;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShopBundle\Security\Annotation\DemoRestricted;
use PrestaShopBundle\Security\Voter\PageVoter;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Admin controller for the Product pages using the Symfony architecture:
 * - product list (display, search)
 * - product form (creation, edition)
 * - ...
 *
 * Some component displayed in this form are based on ajax request which might implemented
 * in another Controller.
 *
 * This controller is a re-migration of the initial ProductController which was the first
 * one to be migrated but doesn't meet the standards of the recently migrated controller.
 * The retro-compatibility is dropped for the legacy Admin pages, the former hook are no longer
 * managed for backward compatibility, new hooks need to be used in the modules, migration process
 * is detailed in the devdoc. (@todo add devdoc link when ready?)
 */
class ProductController extends FrameworkBundleAdminController
{
    /**
     * Used to validate connected user authorizations.
     */
    private const PRODUCT_CONTROLLER_PERMISSION = 'ADMINPRODUCTS_';

    /**
     * Shows products listing.
     *
     * @AdminSecurity("is_granted(['read'], request.get('_legacy_controller'))")
     *
     * @param Request $request
     * @param ProductFilters $filters
     *
     * @return Response
     */
    public function indexAction(Request $request, ProductFilters $filters): Response
    {
        $productGridFactory = $this->get('prestashop.core.grid.factory.product');
        $productGrid = $productGridFactory->getGrid($filters);
        $categoryName = null;

        if (isset($filters->getFilters()['id_category'])) {
            $idFilteredCategory = (int) $filters->getFilters()['id_category'];
            $category = $this->getCommandBus()->handle(new GetCategoryForEditing($idFilteredCategory));
            $categoryName = $category->getName()[$this->getContextLangId()];
        }

        $categoriesForm = $this->createForm(ProductCategories::class);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/index.html.twig', [
            'categories' => $categoriesForm->createView(),
            'selectedCategoryName' => $categoryName,
            'productGrid' => $this->presentGrid($productGrid),
            'enableSidebar' => true,
            'layoutHeaderToolbarBtn' => $this->getProductToolbarButtons(),
            'help_link' => $this->generateSidebarLink('AdminProducts'),
        ]);
    }

    /**
     * @AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message="You do not have permission to create this.")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createAction(Request $request): Response
    {
        if (!$this->get('prestashop.adapter.shop.context')->isSingleShopContext()) {
            return $this->renderDisableMultistorePage();
        }

        $productForm = $this->getCreateProductFormBuilder()->getForm();

        try {
            $productForm->handleRequest($request);

            $result = $this->getProductFormHandler()->handle($productForm);

            if ($result->isSubmitted() && $result->isValid()) {
                $this->addFlash('success', $this->trans('Successful update.', 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_products_v2_edit', ['productId' => $result->getIdentifiableObjectId()]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->renderCreateProductForm($productForm, $request->query->has('liteDisplaying'));
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="You do not have permission to update this.")
     *
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    public function editAction(Request $request, int $productId): Response
    {
        if (!$this->get('prestashop.adapter.shop.context')->isSingleShopContext()) {
            return $this->renderDisableMultistorePage($productId);
        }

        try {
            $productForm = $this->getEditProductFormBuilder()->getFormFor($productId, [], [
                'product_id' => $productId,
                // @todo: patch/partial update doesn't work good for now (especially multiple empty values) so we use POST for now
                // 'method' => Request::METHOD_PATCH,
                'method' => Request::METHOD_POST,
            ]);
        } catch (ShopAssociationNotFound $e) {
            return $this->renderMissingAssociation($productId);
        }

        try {
            $productForm->handleRequest($request);

            $result = $this->getProductFormHandler()->handleFor($productId, $productForm);

            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('Successful update.', 'Admin.Notifications.Success'));

                    return $this->redirectToRoute('admin_products_v2_edit', ['productId' => $productId]);
                } else {
                    // Display root level errors with flash messages
                    foreach ($productForm->getErrors() as $error) {
                        $this->addFlash('error', sprintf(
                            '%s: %s',
                            $error->getOrigin()->getName(),
                            $error->getMessage()
                        ));
                    }
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->renderEditProductForm($productForm, $productId);
    }

    /**
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    public function deleteAction(Request $request, int $productId): Response
    {
        try {
            $this->getCommandBus()->handle(new DeleteProductCommand($productId));
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', 'Admin.Notifications.Success')
            );
        } catch (ProductException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    public function previewAction(Request $request, int $productId): Response
    {
        try {
            $productUrlProvider = $this->get('prestashop.adapter.shop.url.product_provider');
            $url = $productUrlProvider->getUrl($productId, '{friendly-url}');
        } catch (ProductException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));

            return $this->redirectToRoute('admin_products_v2_index');
        }

        return $this->redirect($url);
    }

    /**
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    public function duplicateAction(Request $request, int $productId): Response
    {
        try {
            $this->getCommandBus()->handle(new DuplicateProductCommand($productId));
            $this->addFlash(
                'success',
                $this->trans('Successful duplication', 'Admin.Notifications.Success')
            );
        } catch (ProductException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Toggles product status
     *
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute="admin_products_v2_index")
     * @DemoRestricted(redirectRoute="admin_products_v2_index")
     *
     * @param int $productId
     *
     * @return RedirectResponse
     */
    public function toggleStatusAction(int $productId): RedirectResponse
    {
        try {
            /** @var ProductForEditing $editableProduct */
            $editableProduct = $this->getQueryBus()->handle(new GetProductForEditing((int) $productId));
            $this->getCommandBus()->handle(
                new UpdateProductStatusCommand((int) $productId, !$editableProduct->isActive())
            );
            $this->addFlash(
                'success',
                $this->trans('The status has been successfully updated.', 'Admin.Notifications.Success')
            );
        } catch (ManufacturerException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Updates product position.
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     redirectRoute="admin_products_v2_index",
     *     redirectQueryParamsToKeep={"id_category"},
     *     message="You do not have permission to edit this."
     * )
     * @DemoRestricted(
     *     redirectRoute="admin_products_v2_index",
     *     redirectQueryParamsToKeep={"id_category"}
     * )
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function updatePositionAction(Request $request): RedirectResponse
    {
        try {
            $this->getCommandBus()->handle(
                new UpdateProductPositionCommand(
                    $request->request->get('positions'),
                    $request->query->getInt('id_category')
                )
            );
        } catch (CannotUpdateProductPositionException $e) {
            $errors = $e->getErrors();
            $this->flashErrors($errors);

            return $this->redirectToRoute('admin_products_v2_index');
        }
        $this->addFlash('success', $this->trans('Update successful', 'Admin.Notifications.Success'));

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Delete products in bulk action.
     *
     * @AdminSecurity(
     *     "is_granted('delete', request.get('_legacy_controller'))",
     *     redirectRoute="admin_products_v2_index",
     *     message="You do not have permission to delete this."
     * )
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function bulkDeleteAction(Request $request): RedirectResponse
    {
        try {
            $this->getCommandBus()->handle(new BulkDeleteProductCommand(
                    $this->getProductIdsFromRequest($request))
            );
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Enable products in bulk action.
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     redirectRoute="admin_products_v2_index",
     *     message="You do not have permission to edit this."
     * )
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function bulkEnableAction(Request $request): RedirectResponse
    {
        try {
            $this->getCommandBus()->handle(
                new BulkToggleProductCommand(
                    $this->getProductIdsFromRequest($request),
                    true
                )
            );
            $this->addFlash(
                'success',
                $this->trans('Products successfully activated.', 'Admin.Catalog.Notification')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Disable products in bulk action.
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     redirectRoute="admin_products_v2_index",
     *     message="You do not have permission to edit this."
     * )
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function bulkDisableAction(Request $request): RedirectResponse
    {
        try {
            $this->getCommandBus()->handle(
                new BulkToggleProductCommand(
                    $this->getProductIdsFromRequest($request),
                    false
                )
            );
            $this->addFlash(
                'success',
                $this->trans('Products successfully deactivated.', 'Admin.Catalog.Notification')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * Duplicate products in bulk action.
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     redirectRoute="admin_products_v2_index",
     *     message="You do not have permission to edit this."
     * )
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function bulkDuplicateAction(Request $request): RedirectResponse
    {
        try {
            $this->getCommandBus()->handle(
                new BulkDuplicateProductCommand(
                    $this->getProductIdsFromRequest($request)
                )
            );
            $this->addFlash(
                'success',
                $this->trans('Product(s) successfully duplicated.', 'Admin.Catalog.Notification')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_products_v2_index');
    }

    /**
     * @param Request $request
     *
     * @return array
     */
    private function getProductIdsFromRequest(Request $request): array
    {
        $productIds = $request->request->get('product_bulk');

        if (!is_array($productIds)) {
            return [];
        }

        foreach ($productIds as $i => $productId) {
            $productIds[$i] = (int) $productId;
        }

        return $productIds;
    }

    /**
     * @return array
     */
    private function getProductToolbarButtons(): array
    {
        $toolbarButtons = [];

        $toolbarButtons['add'] = [
            'href' => $this->generateUrl('admin_products_v2_create'),
            'desc' => $this->trans('New product on experimental page', 'Admin.Actions'),
            'icon' => 'add_circle_outline',
            'class' => 'btn-primary new-product',
            'floating_class' => 'new-product',
        ];

        $toolbarButtons['list_v1'] = [
            'href' => $this->generateUrl('admin_product_catalog'),
            'desc' => $this->trans('Back to standard page', 'Admin.Catalog.Feature'),
            'class' => 'btn-outline-primary',
        ];

        return $toolbarButtons;
    }

    /**
     * Download the content of the virtual product.
     *
     * @param int $virtualProductFileId
     *
     * @return BinaryFileResponse
     */
    public function downloadVirtualFileAction(int $virtualProductFileId): BinaryFileResponse
    {
        $configuration = $this->get('prestashop.adapter.legacy.configuration');
        $download = $this->getDoctrine()
            ->getRepository(ProductDownload::class)
            ->findOneBy([
                'id' => $virtualProductFileId,
            ]);

        $response = new BinaryFileResponse(
            $configuration->get('_PS_DOWNLOAD_DIR_') . $download->getFilename()
        );

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $download->getDisplayFilename()
        );

        return $response;
    }

    /**
     * @AdminSecurity("is_granted(['read'], request.get('_legacy_controller'))")
     *
     * @param Request $request
     * @param string $languageCode
     *
     * @return JsonResponse
     */
    public function searchAssociationsAction(Request $request, string $languageCode): JsonResponse
    {
        $langRepository = $this->get('prestashop.core.admin.lang.repository');
        $lang = $langRepository->getOneByLocaleOrIsoCode($languageCode);
        if (null === $lang) {
            return $this->json([
                'message' => sprintf(
                    'Invalid language code %s was used which matches no existing language in this shop.',
                    $languageCode
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        $shopId = $this->get('prestashop.adapter.shop.context')->getContextShopID();
        if (empty($shopId)) {
            $shopId = $this->get('prestashop.adapter.legacy.configuration')->getInt('PS_SHOP_DEFAULT');
        }

        try {
            /** @var ProductForAssociation[] $products */
            $products = $this->getQueryBus()->handle(new SearchProductsForAssociation(
                $request->get('query', ''),
                $lang->getId(),
                (int) $shopId,
                (int) $request->get('limit', 20)
            ));
        } catch (ProductConstraintException $e) {
            return $this->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($products)) {
            return $this->json([], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->formatProductsForAssociation($products));
    }

    /**
     * @param ProductForAssociation[] $productsForAssociation
     *
     * @return array
     */
    private function formatProductsForAssociation(array $productsForAssociation): array
    {
        $productsData = [];
        foreach ($productsForAssociation as $productForAssociation) {
            $productName = $productForAssociation->getName();
            if (!empty($productForAssociation->getReference())) {
                $productName .= sprintf(' (ref: %s)', $productForAssociation->getReference());
            }

            $productsData[] = [
                'id' => $productForAssociation->getProductId(),
                'name' => $productName,
                'image' => $productForAssociation->getImageUrl(),
            ];
        }

        return $productsData;
    }

    /**
     * @param FormInterface $productForm
     *
     * @return Response
     */
    private function renderCreateProductForm(FormInterface $productForm, bool $lightDisplay): Response
    {
        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/create.html.twig', [
            'lightDisplay' => $lightDisplay,
            'showContentHeader' => false,
            'productForm' => $productForm->createView(),
            'helpLink' => $this->generateSidebarLink('AdminProducts'),
            'editable' => $this->isGranted(PageVoter::UPDATE, self::PRODUCT_CONTROLLER_PERMISSION),
        ]);
    }

    /**
     * @param FormInterface $productForm
     * @param int $productId
     *
     * @return Response
     */
    private function renderEditProductForm(FormInterface $productForm, int $productId): Response
    {
        $configuration = $this->get('prestashop.adapter.legacy.configuration');
        $categoryTreeFormBuilder = $this->get('prestashop.core.form.identifiable_object.builder.category_tree_selector_form_builder');

        $moduleDataProvider = $this->get('prestashop.adapter.data_provider.module');
        $statsModule = $moduleDataProvider->findByName('statsproduct');
        $statsLink = null;
        if (!empty($statsModule['active'])) {
            $statsLink = $this->getAdminLink('AdminStats', ['module' => 'statsproduct', 'id_product' => $productId]);
        }

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/edit.html.twig', [
            'categoryTreeSelectorForm' => $categoryTreeFormBuilder->getForm()->createView(),
            'showContentHeader' => false,
            'productForm' => $productForm->createView(),
            'statsLink' => $statsLink,
            'helpLink' => $this->generateSidebarLink('AdminProducts'),
            'editable' => $this->isGranted(PageVoter::UPDATE, self::PRODUCT_CONTROLLER_PERMISSION),
            'taxEnabled' => (bool) $configuration->get('PS_TAX'),
            'stockEnabled' => (bool) $configuration->get('PS_STOCK_MANAGEMENT'),
        ]);
    }

    /**
     * Gets creation form builder.
     *
     * @return FormBuilderInterface
     */
    private function getCreateProductFormBuilder(): FormBuilderInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.builder.create_product_form_builder');
    }

    /**
     * Gets edition form builder.
     *
     * @return FormBuilderInterface
     */
    private function getEditProductFormBuilder(): FormBuilderInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.builder.edit_product_form_builder');
    }

    /**
     * @return FormHandlerInterface
     */
    private function getProductFormHandler(): FormHandlerInterface
    {
        return $this->get('prestashop.core.form.identifiable_object.product_form_handler');
    }

    /**
     * Gets an error by exception class and its code.
     *
     * @param Exception $e
     *
     * @return array
     */
    private function getErrorMessages(Exception $e): array
    {
        // @todo: all the constraint error messages are missing for now (see ProductConstraintException)
        return [
            CannotDeleteProductException::class => $this->trans(
                'An error occurred while deleting the object.',
                'Admin.Notifications.Error'
            ),
            CannotBulkDeleteProductException::class => $this->trans(
                    'An error occurred while deleting this selection.',
                    'Admin.Notifications.Error'
            ),
            ProductConstraintException::class => [
                ProductConstraintException::INVALID_PRICE => $this->trans(
                    'Product price is invalid',
                    'Admin.Notifications.Error'
                ),
                ProductConstraintException::INVALID_UNIT_PRICE => $this->trans(
                    'Product price per unit is invalid',
                    'Admin.Notifications.Error'
                ),
                ProductConstraintException::INVALID_REDIRECT_TARGET => $this->trans(
                    'When redirecting towards a product you must select a target product.',
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ONLINE_DATA => $this->trans(
                    'To put this product online, please enter a name.',
                    'Admin.Catalog.Notification'
                ),
            ],
            DuplicateFeatureValueAssociationException::class => $this->trans(
                'You cannot associate the same feature value more than once.',
                'Admin.Notifications.Error'
            ),
            InvalidAssociatedFeatureException::class => $this->trans(
                'The selected value belongs to another feature.',
                'Admin.Notifications.Error'
            ),
            SpecificPriceConstraintException::class => [
                SpecificPriceConstraintException::DUPLICATE_PRIORITY => $this->trans(
                    'The selected condition must be different in each field to set an order of priority.',
                    'Admin.Notifications.Error'
                ),
            ],
        ];
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    private function renderMissingAssociation(int $productId): Response
    {
        //@todo this error message should be improved to indicate which shop can/should be used for this product and/or how to associate it to the current context
        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/disabled.html.twig', [
            'errorMessage' => $this->trans(
                'This product is not associated with the store selected in the multistore header, please select another one.',
                'Admin.Notifications.Info'
            ),
            'standardPageUrl' => $this->generateUrl(
                !empty($productId) ? 'admin_product_form' : 'admin_product_new',
                !empty($productId) ? ['id' => $productId] : []
            ),
        ]);
    }

    /**
     * @param int|null $productId
     *
     * @return Response
     */
    private function renderDisableMultistorePage(int $productId = null): Response
    {
        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/disabled.html.twig', [
            'errorMessage' => $this->trans(
                'This page is only compatible in a single store context. To access the page, please select a store or [1]disable the multistore feature[/1].',
                'Admin.Notifications.Info',
                [
                    '[1]' => sprintf('<a href="%s">', $this->get('router')->generate('admin_preferences')),
                    '[/1]' => '</a>',
                ]
            ),
            'standardPageUrl' => $this->generateUrl(
                !empty($productId) ? 'admin_product_form' : 'admin_product_new',
                !empty($productId) ? ['id' => $productId] : []
            ),
        ]);
    }
}
